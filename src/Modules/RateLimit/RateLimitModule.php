<?php

declare(strict_types=1);

namespace Phue\Modules\RateLimit;

use Phue\Core\Module;
use Phue\Core\Container;
use Phue\Core\Config;
use Phue\Core\Routing\Router;
use Phue\Core\Console\Application;
use Phue\Modules\RateLimit\Command\RateLimitClearCommand;
use Phue\Modules\RateLimit\Middleware\RateLimitMiddleware;
use Phue\Modules\RateLimit\Policy\PolicyResolver;
use Phue\Modules\RateLimit\Storage\RateLimiterInterface;
use Phue\Modules\RateLimit\Storage\RedisRateLimiter;
use Redis;

/**
 * Rate Limit Module.
 *
 * Provides rate limiting for routes via #[RateLimit] attribute.
 * Uses Redis for distributed rate limiting with sliding window or token bucket algorithms.
 *
 * @package Phue\Modules\RateLimit
 */
class RateLimitModule extends Module
{
    public string $id = 'rateLimit';
    public string $type = 'layer';
    public array $requires = [];

    public function init(): void
    {
        $this->config();
        $this->bindings();
        $this->routes();
    }

    protected function config(): void
    {
        // Config is auto-loaded from config/rate-limit.php by Application
    }

    protected function bindings(): void
    {
        // 1. Redis connection (singleton, shared with other modules)
        $this->container->singleton(Redis::class, function (): Redis {
            $config = $this->container->get(Config::class)->get('rate-limit.redis', []);
            $redis = new Redis();
            $redis->connect(
                $config['host'] ?? '127.0.0.1',
                (int) ($config['port'] ?? 6379),
                (float) ($config['timeout'] ?? 2.0)
            );

            if (!empty($config['password'])) {
                $redis->auth($config['password']);
            }

            if (!empty($config['database'])) {
                $redis->select((int) $config['database']);
            }

            return $redis;
        });

        // 2. Rate limiter implementation
        $this->container->singleton(RateLimiterInterface::class, function (Container $c): RateLimiterInterface {
            $redis = $c->get(Redis::class);
            $prefix = $c->get(Config::class)->get('rate-limit.redis.prefix', 'ratelimit:');
            return new RedisRateLimiter($redis, $prefix);
        });

        // 3. Policy resolver
        $this->container->singleton(PolicyResolver::class, function (Container $c): PolicyResolver {
            return new PolicyResolver($c->get(Config::class));
        });

        // 4. Rate limit middleware - register globally
        $app = $this->container->get(\Phue\Core\Application::class);
        $app->use(RateLimitMiddleware::class);
    }

    protected function routes(): void
    {
        // No HTTP routes for this module
    }

    public function registerCommands(Application $console): void
    {
        $console->add(new RateLimitClearCommand(
            fn(): RateLimiterInterface => $this->container->get(RateLimiterInterface::class)
        ));
    }

    /**
     * Get the middleware class name for global registration.
     * Called by Application after module initialization.
     */
    public function getMiddlewareClass(): string
    {
        return RateLimitMiddleware::class;
    }
}