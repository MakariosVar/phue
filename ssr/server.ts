import { createSSRApp } from "vue";
import { renderToString } from "vue/server-renderer";
import { compile as compileDom } from "@vue/compiler-dom";
import { compile as compileSsr } from "@vue/compiler-ssr";
import { mkdirSync, writeFileSync, existsSync, renameSync } from "fs";
import { join } from "path";
import { createHash } from "crypto";
import vuePkg from "vue/package.json" with { type: "json" };

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

// In-memory cache: hash -> module namespace object
const moduleCache = new Map<string, any>();

// Ensure temporary cache directory exists for module imports
try {
    mkdirSync(CACHE_DIR, { recursive: true });
} catch (e) {
    // Directory already exists or path writable
}

/**
 * Resolves a module from an exact source or pre-computed hash.
 * If source 's' is provided, compiles/caches it atomically under hash 'h'.
 * If only hash 'h' is provided, resolves from in-memory cache or disk.
 */
async function resolveModule(entry: ComponentEntry | string) {
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
        if (!existsSync(filePath)) {
            const tempPath = join(CACHE_DIR, `.${hash}.mjs.tmp`);
            writeFileSync(tempPath, source, "utf-8");
            renameSync(tempPath, filePath);
        }
    } else if (!existsSync(filePath)) {
        throw new Error(`Component hash '${hash}' not found in sidecar cache and no source provided.`);
    }

    // 3. Dynamic import and register in moduleCache
    const mod = await import(filePath);
    moduleCache.set(hash, mod);
    return mod;
}

Bun.serve({
    unix: SOCKET_PATH,
    maxRequestBodySize: 512 * 1024, // 512 KB Limit

    routes: {
        "/v1/health": () => {
            return Response.json({
                ok: true,
                vue: vuePkg.version,
                bun: Bun.version,
            });
        },

        "/v1/compile-template": {
            async POST(req: Request) {
                try {
                    const payload = (await req.json()) as CompilePayload;

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
                    const payload = (await req.json()) as RenderPayload;

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

                    let app: any;

                    if (payload.components && payload.components["PhNavigation"]) {
                        const rawNav = payload.components["PhNavigation"];
                        const mod = await resolveModule(rawNav);

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

                    if (payload.components && typeof payload.components === "object") {
                        for (const [name, rawEntry] of Object.entries(payload.components)) {
                            const mod = await resolveModule(rawEntry);

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