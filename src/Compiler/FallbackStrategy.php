<?php

declare(strict_types=1);

namespace Phue\Compiler;

/**
 * Resolves application execution mode based on compatibility reports.
 *
 * @package Phue\Compiler
 */
final class FallbackStrategy
{
    public const AOT_NATIVE = 'AOT_NATIVE';
    public const PHP_JIT = 'PHP_JIT';
    public const PHP_INTERPRETER = 'PHP_INTERPRETER';

    /**
     * Determines the optimal execution mode for a evaluated class report.
     */
    public function resolveMode(AotCompatibilityReport $report): string
    {
        if ($report->isFullyCompatible()) {
            return self::AOT_NATIVE;
        }

        // If OPcache JIT is enabled in the host environment, delegate non-AOT code to JIT
        if (function_exists('opcache_get_status') && ($status = opcache_get_status(false)) !== false) {
            if (!empty($status['jit']['enabled'])) {
                return self::PHP_JIT;
            }
        }

        return self::PHP_INTERPRETER;
    }
}