<?php

declare(strict_types=1);

namespace Phue\Modules\RateLimit\Middleware;

use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Config;
use Phue\Core\Routing\Router;
use Phue\Modules\RateLimit\Policy\PolicyResolver;
use Phue\Modules\RateLimit\Policy\RateLimitPolicy;
use Phue\Modules\RateLimit\Storage\RateLimiterInterface;
use Phue\Modules\RateLimit\Storage\RateLimitResult;
use ReflectionClass;
use ReflectionMethod;

/**
 * Rate limiting middleware.
 *
 * Runs AFTER routing (route-level) to access route attributes.
 * Checks whitelist, resolves policy, applies rate limit, adds headers.
 *
 * @package Phue\Modules\RateLimit\Middleware
 */
final class RateLimitMiddleware
{
    /**
     * @param RateLimiterInterface $limiter   Storage backend
     * @param PolicyResolver       $resolver  Policy resolution
     * @param Config               $config    Application config
     * @param Router               $router    Router for route matching
     */
    public function __construct(
        private RateLimiterInterface $limiter,
        private PolicyResolver $resolver,
        private Config $config,
        private Router $router
    ) {}

    /**
     * Handle request through rate limiting.
     *
     * @param Request  $request Incoming request
     * @param callable $next    Next middleware/handler
     * @return Response
     */
    public function __invoke(Request $request, callable $next): Response
    {
        // 1. Check if globally disabled
        if (!$this->config->get('rate-limit.enabled', true)) {
            return $next($request);
        }

        // 2. Check whitelist
        if ($this->isWhitelisted($request)) {
            return $next($request);
        }

        // 3. Resolve route and policy
        $policy = $this->resolvePolicy($request);
        if ($policy === null) {
            return $next($request);
        }

        // 4. Resolve rate limit key
        $key = $this->resolveKey($request, $policy);
        if ($key === null) {
            // Cannot identify client, allow but log
            return $next($request);
        }

        // 5. Check rate limit FIRST - don't call controller if limited
        $result = $this->limiter->check($key, $policy);

        // 6. If rate limited, return 429 immediately
        if (!$result->allowed) {
            return $this->buildRateLimitResponse($result);
        }

        // 7. Rate limit passed - call controller
        $response = $next($request);

        // 8. Add rate limit headers to successful response
        $this->addHeaders($response, $result);

        return $response;
    }

    /**
     * Check if request IP is whitelisted.
     */
    private function isWhitelisted(Request $request): bool
    {
        $whitelist = $this->config->get('rate-limit.whitelist', []);
        if (empty($whitelist)) {
            return false;
        }

        $clientIp = $this->getClientIp($request);
        if ($clientIp === null) {
            return false;
        }

        foreach ($whitelist as $entry) {
            if ($this->matchCidr($clientIp, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get client IP from request (handles proxies).
     *
     * X-Forwarded-For is honoured ONLY when the connecting peer is itself a
     * trusted proxy — the same trust gate SecurityMiddleware::isTrustedProxy
     * applies and the same contract docs/Core.md states for
     * app.trustedProxies (empty list trusts nothing, '*' trusts every peer,
     * otherwise the list must contain REMOTE_ADDR verbatim). Trusting the
     * header merely because the list is non-empty would let any client mint
     * a fresh rate-limit bucket per request by rotating X-Forwarded-For, and
     * the per-IP limit would never bind. A peer that fails the gate falls
     * back to REMOTE_ADDR — fail closed, never fail open.
     */
    private function getClientIp(Request $request): ?string
    {
        $serverParams = $request->getServerParams();

        $trustedProxies = $this->config->get('app.trustedProxies', []);

        if (!empty($trustedProxies)) {
            $remoteAddr = (string) ($serverParams['REMOTE_ADDR'] ?? '');
            $trusted = in_array('*', $trustedProxies, true)
                || in_array($remoteAddr, $trustedProxies, true);

            if ($trusted) {
                $forwarded = $request->getHeader('X-Forwarded-For');
                if ($forwarded !== null) {
                    $ips = array_map('trim', explode(',', $forwarded));
                    return $ips[0] ?? null;
                }
            }
        }

        return $serverParams['REMOTE_ADDR'] ?? null;
    }

    /**
     * Match IP against CIDR notation.
     */
    private function matchCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        [$subnet, $mask] = explode('/', $cidr);
        $mask = (int) $mask;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6 not fully supported in simple implementation
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - $mask);
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * Resolve policy for current route.
     */
    private function resolvePolicy(Request $request): ?RateLimitPolicy
    {
        try {
            $routeMatch = $this->router->dispatch($request->getMethod(), $request->getUri());
        } catch (\Throwable) {
            // Route not found or method not allowed - no rate limit
            return null;
        }

        $handler = $routeMatch->getHandler();
        $class = null;
        $method = null;

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
        } elseif (is_array($handler) && is_string($handler[0])) {
            $class = $handler[0];
            $method = $handler[1];
        } elseif (is_object($handler)) {
            if ($handler instanceof \Closure) {
                return null; // Closures don't have attributes
            }
            $class = get_class($handler);
            $method = '__invoke';
        }

        if ($class === null || $method === null) {
            return null;
        }

        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        $reflectionClass = new ReflectionClass($class);
        $reflectionMethod = new ReflectionMethod($class, $method);

        // Get middleware group from route (if available)
        $groupName = $this->getMiddlewareGroup($routeMatch);

        return $this->resolver->resolve($reflectionClass, $reflectionMethod, $groupName);
    }

    /**
     * Extract middleware group name from route match.
     * This is a simplified approach - in practice, you'd need to track
     * which middleware group a route belongs to.
     */
    private function getMiddlewareGroup(\Phue\Core\Routing\RouteMatch $routeMatch): ?string
    {
        // For now, check if route path starts with /api
        $handler = $routeMatch->getHandler();
        // This would need route metadata - skipping for now
        return null;
    }

    /**
     * Resolve rate limit key based on policy.
     */
    private function resolveKey(Request $request, RateLimitPolicy $policy): ?string
    {
        $resolver = $policy->keyResolver;

        // Built-in resolvers
        return match ($resolver) {
            'ip'      => $this->getClientIp($request),
            'user'    => $this->getUserId($request),
            'api_key' => $this->getApiKey($request),
            default   => $this->resolveCustomKey($request, $resolver),
        };
    }

    /**
     * Get authenticated user ID.
     */
    private function getUserId(Request $request): ?string
    {
        try {
            // Try to get from Auth module if available
            $container = \Phue\Core\Container::getInstance();
            if ($container->has(\Phue\Modules\Auth\AuthManager::class)) {
                $auth = $container->get(\Phue\Modules\Auth\AuthManager::class);
                $user = $auth->authenticate($request);
                if ($user !== null && method_exists($user, 'getId')) {
                    return 'user_' . $user->getId();
                }
            }
        } catch (\Throwable) {
            // Ignore
        }
        return null;
    }

    /**
     * Get API key from header.
     */
    private function getApiKey(Request $request): ?string
    {
        $apiKey = $request->getHeader('X-API-Key');
        if ($apiKey !== null) {
            return 'apikey_' . hash('sha256', $apiKey);
        }

        // Also check Authorization: Bearer
        $auth = $request->getHeader('Authorization');
        if ($auth !== null && str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
            return 'apikey_' . hash('sha256', $token);
        }

        return null;
    }

    /**
     * Resolve custom key resolver (callable).
     *
     * The policy declares the resolver as a name ('ip', 'user', 'api_key' —
     * answered by the match() above), a Closure, or a callable name; the
     * callable receives the Request and returns the bucket key. A resolver
     * that throws or returns a non-string is treated as "cannot identify
     * the client", never as a 500.
     */
    private function resolveCustomKey(Request $request, mixed $resolver): ?string
    {
        if (!is_callable($resolver)) {
            return null;
        }

        try {
            $key = $resolver($request);
            return is_string($key) ? $key : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Add rate limit headers to response.
     */
    private function addHeaders(Response $response, RateLimitResult $result): void
    {
        $headers = $result->toHeaders();
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }
    }

    /**
     * Build 429 Too Many Requests response.
     */
    private function buildRateLimitResponse(RateLimitResult $result): Response
    {
        $responseConfig = $this->config->get('rate-limit.response', []);
        $statusCode = $responseConfig['status_code'] ?? 429;
        $message = $responseConfig['message'] ?? 'Too Many Requests';
        $jsonFormat = $responseConfig['json_format'] ?? true;

        $body = $jsonFormat
            ? json_encode([
                'error' => $message,
                'retry_after' => $result->retryAfter,
                'limit' => $result->limit,
                'reset' => $result->reset,
            ])
            : $message;

        $response = new Response($body, $statusCode);
        $response->setHeader('Content-Type', $jsonFormat ? 'application/json' : 'text/plain');
        $this->addHeaders($response, $result);

        return $response;
    }
}