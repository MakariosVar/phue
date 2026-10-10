<?php

declare(strict_types=1);

namespace Phue\Modules\RateLimit\Command;

use Phue\Core\Console\BaseCommandInterface;
use Phue\Core\Container;
use Phue\Modules\RateLimit\Storage\RateLimiterInterface;

/**
 * Console command to clear rate limit data.
 *
 * Usage:
 *   php bin/phue rate-limit:clear           # Clear all
 *   php bin/phue rate-limit:clear --key=ip_127.0.0.1  # Clear specific key
 *
 * @package Phue\Modules\RateLimit\Command
 */
final class RateLimitClearCommand implements BaseCommandInterface
{
    /**
     * @var \Closure|null Lazy resolver for the limiter
     */
    private ?\Closure $limiterResolver = null;

    public function __construct(
        ?\Closure $limiterResolver = null
    ) {
        $this->limiterResolver = $limiterResolver;
    }

    public function getName(): string
    {
        return 'rate-limit:clear';
    }

    public function getDescription(): string
    {
        return 'Clear rate limit data from storage';
    }

    public function getGroup(): string
    {
        return 'Dev';
    }

    public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        // Resolve limiter lazily
        $limiter = $this->limiterResolver !== null ? ($this->limiterResolver)() : null;
        if ($limiter === null && Container::hasInstance()) {
            $limiter = Container::getInstance()->get(RateLimiterInterface::class);
        }
        if ($limiter === null) {
            fwrite($errorStream, "  ! Rate limiter not available (container not initialized)\n");
            return 1;
        }

        // Parse --key option from args
        $key = null;
        foreach ($args as $i => $arg) {
            if ($arg === '--key' || $arg === '-k') {
                $key = $args[$i + 1] ?? null;
                break;
            }
            if (str_starts_with($arg, '--key=')) {
                $key = substr($arg, 6);
                break;
            }
            if (str_starts_with($arg, '-k=')) {
                $key = substr($arg, 3);
                break;
            }
        }

        if ($key !== null) {
            $cleared = $limiter->reset($key);
            if ($cleared) {
                fwrite($outputStream, "  ✓ Cleared rate limit for key: {$key}\n");
            } else {
                fwrite($outputStream, "  ! Key not found: {$key}\n");
            }
            return 0;
        }

        // Clear all
        fwrite($outputStream, "  Clearing all rate limit data... ");
        $limiter->clearAll();
        fwrite($outputStream, "done\n");

        return 0;
    }
}