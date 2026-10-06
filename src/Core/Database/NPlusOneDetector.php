<?php

declare(strict_types=1);

namespace Phue\Core\Database;

use Phue\Core\Database\Attributes\NPlusOneGuard;
use Phue\Core\Http\Exceptions\HttpException;

/**
 * Detects N+1 query patterns by analyzing query fingerprints.
 *
 * Groups queries by normalized fingerprint (SQL structure without bindings)
 * and detects when the same pattern is executed repeatedly within a request.
 *
 * @package Phue\Core\Database
 */
final class NPlusOneDetector
{
    /** @var array<string, array{count: int, sql: string, bindings: array, locations: array<int, string>}> */
    private array $queryGroups = [];

    private int $threshold;
    private bool $throw;

    public function __construct(int $threshold = 5, bool $throw = true)
    {
        $this->threshold = $threshold;
        $this->throw = $throw;
    }

    /**
     * Records a query for N+1 analysis.
     *
     * @param string $sql The SQL query with placeholders
     * @param array $bindings Parameter bindings
     * @return void
     */
    public function record(string $sql, array $bindings = []): void
    {
        $fingerprint = $this->fingerprint($sql);
        $location = $this->getCallerLocation();

        if (!isset($this->queryGroups[$fingerprint])) {
            $this->queryGroups[$fingerprint] = [
                'count' => 0,
                'sql' => $sql,
                'bindings' => $bindings,
                'locations' => [],
            ];
        }

        $this->queryGroups[$fingerprint]['count']++;
        $this->queryGroups[$fingerprint]['locations'][] = $location;
    }

    /**
     * Checks for N+1 patterns and throws/logs if detected.
     *
     * @return void
     * @throws \RuntimeException If N+1 detected and throw is enabled
     */
    public function check(): void
    {
        foreach ($this->queryGroups as $fingerprint => $group) {
            if ($group['count'] >= $this->threshold) {
                $message = $this->buildMessage($group);

                if ($this->throw) {
                    throw new \RuntimeException($message);
                }

                // Log warning instead
                error_log('[N+1 Guard] ' . $message);
            }
        }
    }

    /**
     * Gets all detected query groups for debugging/inspection.
     *
     * @return array<string, array{count: int, sql: string, bindings: array, locations: array<int, string>}>
     */
    public function getGroups(): array
    {
        return $this->queryGroups;
    }

    /**
     * Resets the detector for a new request.
     */
    public function reset(): void
    {
        $this->queryGroups = [];
    }

    /**
     * Creates a normalized fingerprint of the SQL query.
     *
     * Removes literals, normalizes whitespace, keeps structure.
     */
    private function fingerprint(string $sql): string
    {
        // Remove quoted literals
        $sql = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'/', '?', $sql);
        $sql = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '?', $sql);

        // Normalize whitespace
        $sql = preg_replace('/\s+/', ' ', $sql);

        // Remove comments
        $sql = preg_replace('/--[^\n]*/', '', $sql);
        $sql = preg_replace('#/\*.*?\*/#s', '', $sql);

        return trim(strtolower($sql));
    }

    /**
     * Gets the caller location (file:line) outside of framework internals.
     */
    private function getCallerLocation(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            $line = $frame['line'] ?? 0;

            // Skip framework internal files
            if (str_contains($file, '/vendor/')
                || str_contains($file, '/src/Core/Database/')
                || str_contains($file, '/src/Modules/DevTool/')) {
                continue;
            }

            return basename($file) . ':' . $line;
        }

        return 'unknown';
    }

    /**
     * Builds a human-readable detection message.
     */
    private function buildMessage(array $group): string
    {
        $locations = array_slice(array_unique($group['locations']), 0, 5);
        $locationStr = implode(', ', $locations);

        return sprintf(
            "N+1 query detected: %d similar queries executed (threshold: %d)\n" .
            "SQL: %s\n" .
            "Locations: %s\n" .
            "Hint: Use eager loading (with()) or batch loading to fix this.",
            $group['count'],
            $this->threshold,
            $group['sql'],
            $locationStr
        );
    }
}