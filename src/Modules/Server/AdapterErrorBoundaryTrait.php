<?php

declare(strict_types=1);

namespace Phue\Modules\Server;

use Phue\Core\Container;
use Phue\Core\ErrorPages\ErrorHandlerMiddleware;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Security\SecurityMiddleware;
use Throwable;

/**
 * Trait AdapterErrorBoundaryTrait
 *
 * Shared error contract for persistent server adapters (Swoole, RoadRunner).
 *
 * A persistent worker sits OUTSIDE the error boundary of Application::handle():
 * its request loop must decide on its own what to answer when the kernel could
 * not. That decision used to be written twice, by hand, and both copies answered
 * with the raw exception message — handing the DSN, the absolute paths and the
 * failing SQL to whoever managed to trigger the failure. A hand-written second
 * copy of the error contract is also a fork of it: it drifts, and the drift is
 * invisible until an exception escapes a worker in production.
 *
 * This trait keeps that decision in one place and routes it back through the SAME
 * ErrorHandlerMiddleware the HTTP pipeline uses, so a persistent worker answers
 * exactly the way PHP-FPM would: content negotiation, HttpException status and
 * message preservation, logging, and the application's own debug flag.
 *
 * @package Phue\Modules\Server
 */
trait AdapterErrorBoundaryTrait
{
    /**
     * Resolves the container the boundary renders through.
     *
     * Implementations decide how the adapter reaches its container. Swoole caches it
     * at boot; RoadRunner resolves it lazily so the happy path pays for no lookup.
     *
     * @return Container|null Null when the adapter has not been booted.
     */
    abstract protected function errorBoundaryContainer(): ?Container;

    /**
     * Turns a failure the kernel could not handle into a contract-compliant response.
     *
     * @param Throwable    $e      The unhandled failure.
     * @param Request|null $request The translated request, or null when the failure
     *                             happened before the translation completed.
     */
    private function renderFailure(Throwable $e, ?Request $request): Response
    {
        $request ??= new Request('GET', '/');

        $container = $this->errorBoundaryContainer();

        if ($container === null || !$container->has(ErrorHandlerMiddleware::class)) {
            return Response::json(['error' => 'Internal Server Error'], 500);
        }

        try {
            /** @var ErrorHandlerMiddleware $errorHandler */
            $errorHandler = $container->get(ErrorHandlerMiddleware::class);
            /** @var SecurityMiddleware $security */
            $security = $container->get(SecurityMiddleware::class);

            // Rethrowing through the middleware is what makes this a single source of
            // truth: the exception is mapped by the same code that maps it under FPM.
            $response = $errorHandler($request, static fn (): never => throw $e);

            // Application::handle() attaches the security headers after its own try/finally,
            // so a response born outside that boundary never receives them.
            return $security->applyHeaders($response, $request);
        } catch (Throwable) {
            // The error handler itself failed (unreachable logger, broken renderer).
            // There is nothing left to render with, so answer with the smallest possible
            // contract rather than letting the worker die on a second failure.
            return Response::json(['error' => 'Internal Server Error'], 500);
        }
    }
}