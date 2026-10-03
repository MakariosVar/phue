<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use Phue\Core\Request;
use Phue\Core\Response;

/**
 * Class DevToolMiddleware
 *
 * Middleware that profiles the request pipeline, records request/response metadata, and
 * injects the debug toolbar into an HTML response.
 *
 * The profiler is reused across requests under a memory-resident server, so start() is
 * what establishes per-request isolation; this middleware is the outermost hook that can
 * guarantee it runs before any instrumented code.
 *
 * @package Phue\Modules\DevTool
 */
class DevToolMiddleware
{
    /**
     * DevToolMiddleware constructor.
     *
     * @param RequestProfiler $profiler The request profiler instance for timing and memory tracking.
     * @param DebugToolbar $toolbar The debug toolbar HTML renderer.
     * @param array<string, mixed> $options Panel toggles (see config/devtool.php).
     */
    public function __construct(
        private RequestProfiler $profiler,
        private DebugToolbar $toolbar,
        private array $options = []
    ) {}

    /**
     * Handle an incoming request and wrap the response with profiling data and debug toolbar.
     *
     * @param Request $request The incoming HTTP request instance.
     * @param callable $next The next middleware or handler in the onion pipeline.
     * @return Response The modified HTTP response instance.
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $this->profiler->start();

        /** @var Response $response */
        $response = $this->profiler->measure(
            'pipeline',
            static fn(): Response => $next($request)
        );

        $body = (string) $response->getContent();

        $this->profiler->addContext([
            'method' => $request->getMethod(),
            'uri' => $request->getUri(),
            'route' => $this->describeRoute($request),
            'status' => $response->getStatusCode(),
            'response_bytes' => strlen($body),
        ]);

        // Snapshot the duration before rendering: the toolbar's own cost must never
        // be counted as application work, otherwise the report profiles the reporter.
        $duration = $this->profiler->stop();

        // Avoid injecting toolbar into JSON/AJAX or binary responses
        $contentType = $response->getHeader('Content-Type', '');
        if (str_contains($contentType, 'application/json') || $request->isXmlHttpRequest()) {
            return $response;
        }

        if (!$this->shouldInject($contentType)) {
            return $response;
        }

        $injectionPoint = $this->findInjectionPoint($body);
        if ($injectionPoint === null) {
            return $response;
        }

        $renderStartNs = hrtime(true);
        $toolbarHtml = $this->toolbar->render($this->profiler, $duration, $this->options);
        $renderMs = (hrtime(true) - $renderStartNs) / 1_000_000;

        // The toolbar cannot display its own render cost inside itself, and opening a span
        // here would make the panel report a permanently unbalanced stack. Reporting it as
        // a header keeps the cost observable without polluting the span list.
        $response->setHeader('X-Phue-Debug-Toolbar-Ms', number_format($renderMs, 3, '.', ''));

        $response->setContent(
            substr($body, 0, $injectionPoint) . $toolbarHtml . substr($body, $injectionPoint)
        );

        return $response;
    }

    /**
     * Decide whether a response body can host the toolbar at all.
     *
     * @param string $contentType Response Content-Type header.
     * @return bool
     */
    private function shouldInject(string $contentType): bool
    {
        if ($contentType === '') {
            return true;
        }

        $type = strtolower(trim(explode(';', $contentType)[0]));

        return str_contains($type, 'text/html') || str_contains($type, 'application/xhtml');
    }

    /**
     * Locate the byte offset where the toolbar should be inserted.
     *
     * Prefers the last closing body tag, then the last closing html tag, then the end of
     * the document. Returning null means the payload is not markup at all.
     *
     * @param string $body Response body.
     * @return int|null Insertion offset, or null when the body is not injectable.
     */
    private function findInjectionPoint(string $body): ?int
    {
        if ($body === '') {
            return null;
        }

        foreach (['</body>', '</BODY>'] as $needle) {
            $offset = strripos($body, $needle);
            if ($offset !== false) {
                return $offset;
            }
        }

        $offset = strripos($body, '</html>');
        if ($offset !== false) {
            return $offset;
        }

        // A fragment (partial render, streamed chunk) is still worth profiling; append.
        return str_contains($body, '<') ? strlen($body) : null;
    }

    /**
     * Best-effort route description for the report.
     *
     * The router does not expose the matched pattern for an in-flight request, so the
     * request target is reported. The query string is included because it is often what
     * distinguishes one expensive render from another.
     *
     * @param Request $request The incoming request.
     * @return string
     */
    private function describeRoute(Request $request): string
    {
        $uri = $request->getUri();

        if (str_contains($uri, '?')) {
            return $uri;
        }

        $query = $request->getQueryParams();

        // A hand-built Request may carry the query unparsed. Reporting only the path
        // would hide exactly the parameter that distinguishes one expensive render from
        // another, so fall back to splitting it here.
        if ($query === []) {
            return $uri;
        }

        return $uri . '?' . http_build_query($query);
    }
}
