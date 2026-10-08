<?php

declare(strict_types=1);

namespace Phue\Core\ErrorPages;

use InvalidArgumentException;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Routing\Exceptions\MethodNotAllowedException;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Core\Http\Exceptions\HttpException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class ErrorHandlerMiddleware
 *
 * Middleware integration that catches exceptions and routes them through Content Negotiation
 * (API/XHR -> JSON, Browser -> ErrorPageRenderer)
 *
 * @package Phue\Core\ErrorPages
 */
class ErrorHandlerMiddleware
{
    public function __construct(
        private ErrorPageRenderer $renderer,
        private LoggerInterface $logger,
        private bool $debug = false
    ) {}

    public function __invoke(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception: ' . $e->getMessage(), ['exception' => $e]);

            $acceptsJson = str_contains($request->getHeader('Accept', ''), 'application/json')
                || $request->isXmlHttpRequest();

            // Determine status code and message for ALL exceptions, including HttpException.
            // HttpException is handled in the match to preserve its status code and message,
            // then falls through to the common response logic (JSON or HTML via renderer).
            // This avoids the memory leak in the early-return branch that bypassed the renderer.
            $statusCode = match (true) {
                $e instanceof HttpException => $e->getStatusCode(),
                $e instanceof InvalidArgumentException => 400,
                $e instanceof RouteNotFoundException => 404,
                $e instanceof MethodNotAllowedException => 405,
                default => 500,
            };

            $message = $e instanceof HttpException ? $e->getMessage() : null;

            if ($acceptsJson) {
                if ($this->debug) {
                    return Response::json([
                        'error' => $message ?? $e->getMessage(),
                        'class' => get_class($e),
                        'file'  => $e->getFile(),
                        'line'  => $e->getLine(),
                    ], $statusCode);
                }

                $errorMsg = $message ?? match ($statusCode) {
                    400 => 'Bad Request',
                    404 => 'Not Found',
                    405 => 'Method Not Allowed',
                    default => 'Internal Server Error',
                };

                return Response::json(['error' => $errorMsg], $statusCode);
            }

            // Non-JSON: render via ErrorPageRenderer for consistent cleanup
            $html = $this->renderer->render($e, $this->debug, $statusCode);
            $response = (new Response($html, $statusCode))
                ->setHeader('Content-Type', 'text/html; charset=utf-8');

            // Add 'Allow' header for MethodNotAllowedException
            if ($e instanceof MethodNotAllowedException) {
                $response = $response->setHeader('Allow', implode(', ', $e->getAllowedMethods()));
            }

            // Explicitly break potential circular references in exception stack traces
            // (frames may capture $this from closures/objects). Run GC periodically
            // to prevent unbounded growth in long-lived Swoole workers.
            self::maybeCollectGarbage();

            return $response;
        }
    }

    /**
     * Run garbage collection periodically to break circular references
     * in exception stack traces (frames may capture $this from closures/objects).
     * Cheap amortized cost; critical for Swoole workers that run indefinitely.
     */
    private static int $requestCount = 0;

    private static function maybeCollectGarbage(): void
    {
        if (++self::$requestCount >= 100) {
            self::$requestCount = 0;
            gc_collect_cycles();
        }
    }
}