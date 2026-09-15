<?php

declare(strict_types=1);

namespace Phue\Schii\Audit;

/**
 * Audit engine for logging and retrieving administrative actions.
 *
 * @package Phue\Schii\Audit
 */
final class SchiiAudit
{
    /**
     * In-memory or storage log storage.
     *
     * @var array<int, array{entity: string, verb: string, user: string|null, timestamp: int, payload: mixed}>
     */
    private static array $logs = [];

    /**
     * Record an audit log entry.
     *
     * @param string      $entity Target entity name.
     * @param string      $verb   HTTP verb or action name (e.g., GET, POST, DELETE).
     * @param string|null $user   User identifier or username.
     * @param mixed       $payload Optional additional data.
     *
     * @return void
     */
    public static function log(string $entity, string $verb, ?string $user = null, mixed $payload = null): void
    {
        self::$logs[] = [
            'entity' => $entity,
            'verb' => strtoupper($verb),
            'user' => $user,
            'timestamp' => time(),
            'payload' => $payload,
        ];
    }

    /**
     * Retrieve filtered audit logs.
     *
     * @param string|null $entity Optional entity filter.
     * @param string|null $verb   Optional verb filter.
     * @param string|null $user   Optional user filter.
     * @param int         $limit  Maximum number of records to return (0-200, default 50).
     *
     * @return array<int, array{entity: string, verb: string, user: string|null, timestamp: int, payload: mixed}>
     */
    public static function getLogs(?string $entity = null, ?string $verb = null, ?string $user = null, int $limit = 50): array
    {
        $limit = max(0, min(200, $limit));

        $filtered = array_filter(self::$logs, function (array $log) use ($entity, $verb, $user) {
            if ($entity !== null && strcasecmp($log['entity'], $entity) !== 0) {
                return false;
            }
            if ($verb !== null && strcasecmp($log['verb'], $verb) !== 0) {
                return false;
            }
            if ($user !== null && $log['user'] !== $user) {
                return false;
            }
            return true;
        });

        // Return latest logs up to limit
        return array_slice(array_reverse(array_values($filtered)), 0, $limit);
    }
}