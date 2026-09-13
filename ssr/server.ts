import { createSSRApp } from "vue";
import { renderToString } from "vue/server-renderer";
import { compile as compileDom } from "@vue/compiler-dom";
import { compile as compileSsr } from "@vue/compiler-ssr";
import { mkdirSync, writeFileSync } from "fs";
import { join } from "path";
import { createHash } from "crypto";
import vuePkg from "vue/package.json" with { type: "json" };

interface RenderPayload {
    template?: string;
    data?: Record<string, any>;
    components?: Record<string, string>;
}

interface CompilePayload {
    template?: string;
    ssr?: boolean;
}

const SOCKET_PATH = process.env.PHUE_SSR_SOCKET || "/tmp/phue-ssr.sock";
const CACHE_DIR = join(import.meta.dir, ".cache");

// Ensure temporary cache directory exists for module imports
try {
    mkdirSync(CACHE_DIR, { recursive: true });
} catch (e) {
    // Directory already exists or path writable
}

/**
 * Writes source to a temporary file and dynamically imports it.
 * Resolves bare imports like 'vue' cleanly via node/bun module resolution.
 */
async function loadModuleFromSource(source: string) {
    const hash = createHash("sha256").update(source).digest("hex").slice(0, 12);
    const filePath = join(CACHE_DIR, `${hash}.mjs`);

    writeFileSync(filePath, source, "utf-8");
    return await import(filePath);
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

                    // Use @vue/compiler-ssr if ssr flag is passed, otherwise fall back to compiler-dom
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
                        const source = payload.components["PhNavigation"];
                        const mod = await loadModuleFromSource(source);

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

                        // Pass payload.data as rootProps specifically for PhNavigation component hydration
                        app = createSSRApp(rootComponent, payload.data || {});

                        // Configure a stable application ID prefix for useId() alignment
                        app.config.idPrefix = "phue-ssr";
                    } else {
                        // Pass payload.data ONLY to the data option to avoid attribute fallthrough leaks
                        app = createSSRApp({
                            template: payload.template,
                            data: () => payload.data || {},
                        });
                    }

                    if (
                        payload.components &&
                        typeof payload.components === "object"
                    ) {
                        for (const [name, source] of Object.entries(
                            payload.components,
                        )) {
                            if (
                                typeof source === "string" &&
                                source.trim() !== ""
                            ) {
                                const mod = await loadModuleFromSource(source);

                                const definition: any = {
                                    ...(mod.default || {}),
                                    ssrRender:
                                        mod.__phueSsrRender ??
                                        mod.default?.ssrRender,
                                    template:
                                        mod.__phueTemplate ??
                                        mod.default?.template,
                                };

                                const resolvedRender =
                                    mod.__phueRender ?? mod.default?.render;
                                if (resolvedRender) {
                                    definition.render = resolvedRender;
                                }

                                app.component(name, definition);
                            }
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