<?php

declare(strict_types=1);

namespace Phue\Core\Security;

use Phue\Core\Request;
use Phue\Core\Response;

/**
 * Enterprise Production Security Hardening Middleware (Onion Compliant).
 *
 * Provides central security headers management (CSP, HSTS, frame options, etc.)
 * across the middleware pipeline as well as direct error handler fallback paths.
 *
 * @package Phue\Core\Security
 */
final class SecurityMiddleware
{
    /**
     * Process an incoming server request and apply security headers to the resulting response.
     *
     * @param Request $request Current HTTP request instance.
     * @param callable $next Next middleware layer callable in the pipeline execution stack.
     * 
     * @return Response Standardized HTTP response object with security headers applied.
     */
    public function __invoke(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return self::applyHeaders($response, $request);
    }

    /**
     * Single source of truth to apply enterprise security headers to any HTTP Response.
     *
     * Serves both normal middleware execution flow and exception fallback paths (e.g., 400, 404, 405, 500).
     *
     * @param Response $response The target response object.
     * @param Request|null $request Optional incoming request to evaluate context (e.g., HTTPS state for HSTS).
     * 
     * @return Response Modified response instance containing default security headers.
     */
    public static function applyHeaders(Response $response, ?Request $request = null): Response
    {
        // Note: 'unsafe-inline' is required for Client-side Hydration state (__INITIAL_STATE__)
        $securityHeaders = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';",
        ];

        // HSTS must only be emitted over secure HTTPS connections
        if ($request !== null && self::isSecure($request)) {
            $securityHeaders['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        foreach ($securityHeaders as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }

    /**
     * Detect whether the incoming HTTP request is operating over a secure connection (HTTPS).
     *
     * Checks both direct server attributes and reverse-proxy protocol headers.
     *
     * @param Request $request Request object to inspect.
     * 
     * @return bool True if the connection is HTTPS/secure, false otherwise.
     */
    private static function isSecure(Request $request): bool
    {
        $server = $request->getServerParams();
        $https = $server['HTTPS'] ?? '';
        $proto = $request->getHeader('X-Forwarded-Proto');

        return (!empty($https) && strtolower((string) $https) !== 'off') 
            || strtolower((string) ($proto ?? '')) === 'https';
    }
}