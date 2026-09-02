<?php

declare(strict_types=1);

namespace Phue\Core\Security;

use Phue\Core\Response;

/**
 * Enterprise Production Security Hardening Middleware.
 *
 * Automatically injects essential OWASP HTTP defense headers into all outgoing responses,
 * guarding against Clickjacking, MIME sniffing, XSS attacks, and unencrypted traffic.
 *
 * @package Phue\Core\Security
 */
final class SecurityMiddleware
{
    /**
     * Injects strict production security headers into the HTTP Response object.
     *
     * @param Response $response Incoming HTTP Response.
     * @return Response Hardened HTTP Response.
     */
    public function handle(Response $response): Response
    {
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