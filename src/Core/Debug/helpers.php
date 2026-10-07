<?php

declare(strict_types=1);

use Phue\Core\Database\QueryBuilder;
use Phue\Core\Container;
use Phue\Core\Debug\AsyncDumper;

/**
 * Records a full debugging report for a query and lets execution continue.
 *
 * @param QueryBuilder $query QueryBuilder instance
 * @return array<string, mixed> The query report
 */
if (!function_exists('dd')) {
    function dd(QueryBuilder $query): array
    {
        return $query->dd();
    }
}

/**
 * Dump variable using AsyncDumper (request-scoped, persistent-safe).
 *
 * @param mixed $value Value to dump
 * @param string $label Optional label
 * @param string $group Optional group
 * @return void
 */
if (!function_exists('dump')) {
    function dump(mixed $value, string $label = '', string $group = 'default'): void
    {
        $container = \Phue\Core\Container::getInstance();
        if ($container) {
            try {
                $dumper = $container->get(\Phue\Core\Debug\AsyncDumper::class);
                if ($dumper) {
                    $dumper->dump($value, $label, $group);
                    return;
                }
            } catch (\Throwable) {
                // Dumper not available yet - silently skip to avoid breaking response
                return;
            }
        }
        // No var_dump fallback - it breaks HTTP responses
        // Dump silently skipped if dumper not available
    }
}

/**
 * Conditionally dump a value.
 *
 * @param bool $condition Condition to evaluate
 * @param mixed $value Value to dump
 * @param string $label Optional label
 * @param string $group Optional group
 * @return void
 */
if (!function_exists('dump_if')) {
    function dump_if(bool $condition, mixed $value, string $label = '', string $group = 'default'): void
    {
        if ($condition) {
            dump($value, $label, $group);
        }
    }
}

/**
 * Dump value when callable returns true.
 *
 * @param callable(): bool $condition Callable condition
 * @param mixed $value Value to dump
 * @param string $label Optional label
 * @param string $group Optional group
 * @return void
 */
if (!function_exists('dump_when')) {
    function dump_when(callable $condition, mixed $value, string $label = '', string $group = 'default'): void
    {
        if ($condition()) {
            dump($value, $label, $group);
        }
    }
}

/**
 * Dump value with sampling rate.
 *
 * @param float $rate Sampling rate (0.0 - 1.0)
 * @param mixed $value Value to dump
 * @param string $label Optional label
 * @param string $group Optional group
 * @return void
 */
if (!function_exists('dump_sample')) {
    function dump_sample(float $rate, mixed $value, string $label = '', string $group = 'default'): void
    {
        if ($rate > 0 && mt_rand() / mt_getrandmax() <= $rate) {
            dump($value, $label, $group);
        }
    }
}