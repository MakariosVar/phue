import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import vuePkg from 'vue/package.json' with { type: 'json' };

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
          const payload = (await req.json()) as { template?: string; data?: Record<string, any> };

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