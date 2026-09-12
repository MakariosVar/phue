import { createSSRApp } from "vue";
import { renderToString } from "vue/server-renderer";
import { compile } from "@vue/compiler-dom";
import vuePkg from "vue/package.json" with { type: "json" };

interface RenderPayload {
    template?: string;
    data?: Record<string, any>;
    components?: Record<string, string>;
}

interface CompilePayload {
    template?: string;
}

const SOCKET_PATH = process.env.PHUE_SSR_SOCKET || "/tmp/phue-ssr.sock";

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

                    // Mode switched to "module" to generate valid standalone ESM code
                    const compiled = compile(payload.template, {
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

                    const app = createSSRApp({
                        template: payload.template,
                        data: () => payload.data || {},
                    });

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
                                const dataUrl = `data:text/javascript;base64,${Buffer.from(source).toString("base64")}`;
                                const mod = await import(dataUrl);

                                const definition = {
                                    ...(mod.default || {}),
                                    render:
                                        mod.__phueRender ?? mod.default?.render,
                                    template:
                                        mod.__phueTemplate ??
                                        mod.default?.template,
                                };

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