import { createSSRApp } from "vue";
import { renderToString } from "vue/server-renderer";
import { compile as compileDom } from "@vue/compiler-dom";
import { compile as compileSsr } from "@vue/compiler-ssr";
import { mkdirSync, writeFileSync, existsSync, renameSync } from "fs";
import { join } from "path";
import { createHash } from "crypto";
import vuePkg from "vue/package.json" with { type: "json" };
import { installI18n } from "../vendor/makariosvar/phue/src/Core/UI/runtime/i18n.js";

interface ComponentEntry {
    h: string;
    s?: string;
}

interface RenderPayload {
    template?: string;
    data?: Record<string, any>;
    components?: Record<string, string | ComponentEntry>;
}

interface CompilePayload {
    template?: string;
    ssr?: boolean;
}

const SOCKET_PATH = process.env.PHUE_SSR_SOCKET || "/tmp/phue-ssr.sock";
const CACHE_DIR = join(import.meta.dir, ".cache");

// Where the `phue` import-map specifier actually lives on disk. Resolved from
// this file so a deployed app finds the runtime wherever the installer put
// src/Core, rather than at a path hardcoded here.
const RUNTIME_MODULE_PATH = join(import.meta.dir, "..", "vendor", "makariosvar", "phue", "src", "Core", "UI", "runtime", "createPhueApp.js");

// In-memory cache: hash -> module namespace object
const moduleCache = new Map<string, any>();

// Stub module returned when a component is missing so execution continues without throwing
const STUB_MODULE = {
    default: { render: () => null },
    __phueSsrRender: () => null,
    __phueRender: () => null,
    __phueTemplate: "",
};

// Ensure temporary cache directory exists for module imports
try {
    mkdirSync(CACHE_DIR, { recursive: true });
} catch (e) {
    // Directory already exists or path writable
}

/**
 * Helper to parse JSON payload with optional gzip/deflate decompression.
 */
async function parseJsonPayload<T>(req: Request): Promise<T> {
    const encoding = req.headers.get("content-encoding")?.toLowerCase();

    if (encoding === "gzip") {
        const arrayBuffer = await req.arrayBuffer();
        const decompressed = Bun.gunzipSync(new Uint8Array(arrayBuffer));
        const text = new TextDecoder().decode(decompressed);
        return JSON.parse(text) as T;
    }

    if (encoding === "deflate") {
        const arrayBuffer = await req.arrayBuffer();
        const decompressed = Bun.inflateSync(new Uint8Array(arrayBuffer));
        const text = new TextDecoder().decode(decompressed);
        return JSON.parse(text) as T;
    }

    return (await req.json()) as T;
}

/**
 * Resolves a module from an exact source or pre-computed hash.
 * If source 's' is provided, compiles/caches it atomically under hash 'h'.
 * If only hash 'h' is provided, resolves from in-memory cache or disk.
 * Accepts an optional missingHashes Set to collect misses instead of throwing instantly.
 */
async function resolveModule(entry: ComponentEntry | string, missingHashes?: Set<string>) {
    let hash: string;
    let source: string | undefined;

    if (typeof entry === "string") {
        source = entry;
        hash = createHash("sha256").update(source).digest("hex").slice(0, 12);
    } else {
        hash = entry.h;
        source = entry.s;
    }

    // 1. In-memory Map cache lookup
    if (moduleCache.has(hash)) {
        return moduleCache.get(hash);
    }

    const filePath = join(CACHE_DIR, `${hash}.mjs`);

    // 2. Transmit/Write source if present
    if (source) {
        // Safety net: the SSR build no longer imports `phue`, but if one ever
        // does, Bun has no import map. Rewritten after the hash is taken, so
        // the PHP-side cache still matches.
        const resolvable = source.replace(
            /(['"])phue\1/g,
            JSON.stringify(RUNTIME_MODULE_PATH)
        );
        if (!existsSync(filePath)) {
            const tempPath = join(CACHE_DIR, `.${hash}.mjs.tmp`);
            writeFileSync(tempPath, resolvable, "utf-8");
            renameSync(tempPath, filePath);
        }
    } else if (!existsSync(filePath)) {
        if (missingHashes) {
            missingHashes.add(hash);
            return STUB_MODULE;
        }
        throw new Error(`Component hash '${hash}' not found in sidecar cache and no source provided.`);
    }

    // 3. Dynamic import and register in moduleCache
    const mod = await import(filePath);
    moduleCache.set(hash, mod);
    return mod;
}

const parsedPayloadKb = parseInt(process.env.SSR_MAX_PAYLOAD_KB || "2048", 10);
const maxPayloadKb = Number.isFinite(parsedPayloadKb) && parsedPayloadKb > 0 ? parsedPayloadKb : 2048;
const maxRequestBodySize = maxPayloadKb * 1024;

Bun.serve({
    unix: SOCKET_PATH,
    maxRequestBodySize,

    routes: {
        "/v1/health": () => {
            return Response.json({
                ok: true,
                vue: vuePkg.version,
                bun: Bun.version,
                maxRequestBodySize,
            });
        },

        "/v1/compile-template": {
            async POST(req: Request) {
                try {
                    const payload = await parseJsonPayload<CompilePayload>(req);

                    if (!payload || typeof payload.template !== "string") {
                        return Response.json(
                            { ok: false, error: "Missing template string" },
                            { status: 400 },
                        );
                    }

                    const compileFn = payload.ssr ? compileSsr : compileDom;
                    const compiled = compileFn(payload.template, {
                        mode: "module",
                    });

                    return Response.json({ ok: true, code: compiled.code });
                } catch (err: any) {
                    return Response.json(
                        { ok: false, error: err?.message || String(err) },
                        { status: 500 },
                    );
                }
            },
        },

        "/v1/render": {
            async POST(req: Request) {
                try {
                    const payload = await parseJsonPayload<RenderPayload>(req);

                    if (!payload || typeof payload.template !== "string") {
                        return Response.json(
                            {
                                ok: false,
                                error: {
                                    code: "INVALID_PAYLOAD",
                                    message:
                                        'Missing or invalid "template" property in payload.',
                                },
                            },
                            { status: 400 },
                        );
                    }

                    // Context Set for collecting all missing hashes during render invocation
                    const missingHashes = new Set<string>();

                    let app: any;

                    if (payload.components && payload.components["PhNavigation"]) {
                        const rawNav = payload.components["PhNavigation"];
                        const mod = await resolveModule(rawNav, missingHashes);

                        const rootComponent: any = {
                            ...(mod.default || {}),
                            ssrRender: mod.__phueSsrRender ?? mod.default?.ssrRender,
                        };

                        const resolvedRootRender = mod.__phueRender ?? mod.default?.render;
                        if (resolvedRootRender) {
                            rootComponent.render = resolvedRootRender;
                        } else if (mod.__phueSsrRender ?? mod.default?.ssrRender) {
                            rootComponent.render = mod.__phueSsrRender ?? mod.default?.ssrRender;
                        }

                        app = createSSRApp(rootComponent, payload.data || {});
                        app.config.idPrefix = "phue-ssr";
                    } else {
                        app = createSSRApp({
                            template: payload.template,
                            data: () => payload.data || {},
                        });
                    }

                    // Bound AFTER construction: the snapshot is closed over the
                    // app instance rather than parked on globalThis, so a
                    // concurrent render cannot read another request's locale.
                    if (payload.i18n) {
                        installI18n(app, payload.i18n);
                    }

                    if (payload.components && typeof payload.components === "object") {
                        for (const [name, rawEntry] of Object.entries(payload.components)) {
                            const mod = await resolveModule(rawEntry, missingHashes);

                            const definition: any = {
                                ...(mod.default || {}),
                                ssrRender: mod.__phueSsrRender ?? mod.default?.ssrRender,
                                template: mod.__phueTemplate ?? mod.default?.template,
                            };

                            const resolvedRender = mod.__phueRender ?? mod.default?.render;
                            if (resolvedRender) {
                                definition.render = resolvedRender;
                            }

                            app.component(name, definition);
                        }
                    }

                    // If missing hashes are found, return immediately with the complete list without calling renderToString
                    if (missingHashes.size > 0) {
                        const missingArray = Array.from(missingHashes);
                        return Response.json({
                            ok: false,
                            error: {
                                code: "CACHE_MISS",
                                message: `Component hashes not found in sidecar cache: ${missingArray.map(h => `'${h}'`).join(", ")}`,
                                missing: missingArray,
                            },
                        });
                    }

                    const html = await renderToString(app);

                    return Response.json({ ok: true, html });
                } catch (err: any) {
                    return Response.json(
                        {
                            ok: false,
                            error: {
                                code: "COMPILE_ERROR",
                                message: err?.message || String(err),
                            },
                        },
                        { status: 500 },
                    );
                }
            },
        },
    },

    fetch(req: Request) {
        return Response.json(
            {
                ok: false,
                error: {
                    code: "NOT_FOUND",
                    message: `Route ${req.method} ${new URL(req.url).pathname} not found`,
                },
            },
            { status: 404 },
        );
    },
});