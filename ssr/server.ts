import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import vuePkg from 'vue/package.json' with { type: 'json' };

interface RenderPayload {
  template?: string;
  data?: Record<string, any>;
  components?: Record<string, string>;
}

Bun.serve({
  unix: '\0phue-ssr',
  maxRequestBodySize: 512 * 1024, // 512 KB Limit

  routes: {
    // Healthcheck endpoint (Compliant with Contract 3.2)
    '/v1/health': () => {
      return Response.json({
        ok: true,
        vue: vuePkg.version,
        bun: Bun.version,
      });
    },

    // Main SSR rendering endpoint
    '/v1/render': {
      async POST(req: Request) {
        try {
          const payload = (await req.json()) as RenderPayload;

          if (!payload || typeof payload.template !== 'string') {
            return Response.json(
              {
                ok: false,
                error: {
                  code: 'INVALID_PAYLOAD',
                  message: 'Missing or invalid "template" property in payload.',
                },
              },
              { status: 400 }
            );
          }

          // Let Vue handle template compilation & internal caching natively
          const app = createSSRApp({
            template: payload.template,
            data: () => payload.data || {},
          });

          // Dynamically import and register component dependencies via data-URLs
          if (payload.components && typeof payload.components === 'object') {
            for (const [name, source] of Object.entries(payload.components)) {
              if (typeof source === 'string' && source.trim() !== '') {
                const dataUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
                const mod = await import(dataUrl);

                const definition = {
                  ...(mod.default || {}),
                  template: mod.__phueTemplate ?? mod.default?.template,
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
                code: 'COMPILE_ERROR',
                message: err?.message || String(err),
              },
            },
            { status: 500 }
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
          code: 'NOT_FOUND',
          message: `Route ${req.method} ${new URL(req.url).pathname} not found`,
        },
      },
      { status: 404 }
    );
  },
});