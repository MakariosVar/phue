<?php

declare(strict_types=1);

namespace Phue\Core\DevTool;

use Phue\Core\Request;
use Phue\Core\Response;

/**
 * Class DevToolMiddleware
 *
 * Middleware that intercepts the response pipeline, profiles execution time and memory,
 * and injects the debug toolbar HTML into the response body if applicable.
 *
 * @package Phue\Core\DevTool
 */
class DevToolMiddleware
{
    /**
     * DevToolMiddleware constructor.
     *
     * @param RequestProfiler $profiler The request profiler instance for timing and memory tracking.
     * @param DebugToolbar $toolbar The debug toolbar HTML renderer.
     */
    public function __construct(
        private RequestProfiler $profiler,
        private DebugToolbar $toolbar
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
        $response = $next($request);

        $duration = $this->profiler->stop();

        // Avoid injecting toolbar into JSON/AJAX or binary responses
        $contentType = $response->getHeader('Content-Type', '');
        if (str_contains($contentType, 'application/json') || $request->isXmlHttpRequest()) {
            return $response;
        }

        $body = $response->getContent();
        // Inject HTML toolbar right before the closing
        if (str_contains(strtolower($body), '</body>')) {
            $toolbarHtml = $this->toolbar->render($this->profiler, $duration);
            $newBody = str_ireplace('</body>', $toolbarHtml . '</body>', $body);
            $response->setContent($newBody);
        }

        return $response;
    }
}