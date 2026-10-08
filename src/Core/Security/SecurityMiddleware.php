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
     * List of trusted proxy IP addresses or wildcard ['*'].
     *
     * @var array<int, string>
     */
    private array $trustedProxies;

    /**
     * Initializes the security middleware with trusted proxies configuration.
     *
     * @param array<int, string> $trustedProxies List of trusted IP addresses or ['*'] for all proxies.
     */
    public function __construct(array $trustedProxies = [])
    {
        $this->trustedProxies = $trustedProxies;
    }

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

        return $this->applyHeaders($response, $request);
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
    public function applyHeaders(Response $response, ?Request $request = null): Response
    {
        // Note: 'unsafe-inline' is required for Client-side Hydration state (__INITIAL_STATE__)
        // Note: 'data:' is required under img-src for Bootstrap inline SVG sprites (.form-select, .btn-close, etc.)
        $securityHeaders = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;",
        ];

        // HSTS must only be emitted over secure HTTPS connections
        if ($request !== null && $this->isSecure($request)) {
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
     * Checks direct HTTPS status or validates X-Forwarded-Proto against configured trusted proxies.
     *
     * Public because it is not private knowledge of the header middleware: anything that
     * decides on a transport-dependent attribute (a Secure cookie, a redirect) has to agree
     * with this about what counts as HTTPS, including which proxies may assert it. Two
     * copies of this logic would eventually disagree, and the disagreement would show up
     * only behind a proxy in production.
     *
     * @param Request $request Request object to inspect.
     * 
     * @return bool True if the connection is HTTPS/secure, false otherwise.
     */
    public function isSecure(Request $request): bool
    {
        $server = $request->getServerParams();
        $https = $server['HTTPS'] ?? '';

        if (!empty($https) && strtolower((string) $https) !== 'off') {
            return true;
        }

        $remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '');
        $proto = $request->getHeader('X-Forwarded-Proto');

        if (strtolower((string) ($proto ?? '')) === 'https') {
            return $this->isTrustedProxy($remoteAddr);
        }

        return false;
    }

    /**
     * Verify whether the given client/proxy IP address is trusted.
     *
     * @param string $ip Remote client or proxy IP address.
     * 
     * @return bool True if the IP is trusted or if wildcard '*' is set, false otherwise.
     */
    private function isTrustedProxy(string $ip): bool
    {
        if (empty($this->trustedProxies)) {
            return false;
        }

        if (in_array('*', $this->trustedProxies, true)) {
            return true;
        }

        return in_array($ip, $this->trustedProxies, true);
    }
}