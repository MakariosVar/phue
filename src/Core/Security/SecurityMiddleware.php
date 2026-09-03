<?php

declare(strict_types=1);

namespace Phue\Core\Security;

use Phue\Core\Request;
use Phue\Core\Response;

/**
 * Enterprise Production Security Hardening Middleware (Onion Compliant).
 *
 * Automatically injects essential OWASP HTTP defense headers into outgoing responses
 * within the HTTP execution pipeline.
 *
 * @package Phue\Core\Security
 */
final class SecurityMiddleware
{
    /**
     * Executes the middleware layer within the Onion pipeline.
     *
     * Intercepts the HTTP request, delegates execution down the pipeline,
     * and attaches OWASP security headers to the resulting response.
     *
     * @param Request $request Incoming HTTP Request object.
     * @param callable $next Next middleware closure in the pipeline: fn(Request): Response
     * @return Response Hardened HTTP Response object containing security headers.
     */
    public function __invoke(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $securityHeaders = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '1; mode=block',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';",
        ];

        foreach ($securityHeaders as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }
}