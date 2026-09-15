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

            // Early return for HttpException so that custom messages and status codes 
            // are always preserved verbatim, even in production mode.
            if ($e instanceof HttpException) {
                $statusCode = $e->getStatusCode();
                if ($acceptsJson) {
                    $response = Response::json(['error' => $e->getMessage()], $statusCode);
                } else {
                    $response = new Response($e->getMessage(), $statusCode);
                }

                return $response;
            }

            // Determine status code based on exception type for other exceptions
            $statusCode = match (true) {
                $e instanceof InvalidArgumentException => 400,
                $e instanceof RouteNotFoundException => 404,
                $e instanceof MethodNotAllowedException => 405,
                default => 500,
            };

            if ($acceptsJson) {
                if ($this->debug) {
                    return Response::json([
                        'error' => $e->getMessage(),
                        'class' => get_class($e),
                        'file'  => $e->getFile(),
                        'line'  => $e->getLine(),
                    ], $statusCode);
                }

                $errorMsg = match ($statusCode) {
                    400 => 'Bad Request',
                    404 => 'Not Found',
                    405 => 'Method Not Allowed',
                    default => 'Internal Server Error',
                };

                $response = Response::json(['error' => $errorMsg], $statusCode);
            } else {
                $html = $this->renderer->render($e, $this->debug, $statusCode);
                $response = (new Response($html, $statusCode))
                    ->setHeader('Content-Type', 'text/html; charset=utf-8');
            }

            // Add 'Allow' header for MethodNotAllowedException
            if ($e instanceof MethodNotAllowedException) {
                $response = $response->setHeader('Allow', implode(', ', $e->getAllowedMethods()));
            }

            return $response;
        }
    }
}