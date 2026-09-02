<?php

declare(strict_types=1);

namespace Phue\Core\Health;

use PDO;
use Redis;
use Throwable;

/**
 * Diagnostic Health Checker for Cloud-Native Production Monitoring.
 *
 * Performs real-time checks on Database connectivity, Redis availability,
 * system memory consumption, and disk space usage for Kubernetes/Load Balancer probes.
 *
 * @package Phue\Core\Health
 */
final class HealthChecker
{
    /**
     * Initializes the HealthChecker instance with optional services.
     *
     * @param PDO|null $pdo Connected PDO database instance.
     * @param Redis|null $redis Connected Redis instance.
     * @param float $maxMemoryThresholdMb Maximum allowable memory limit in MB (default: 128 MB).
     */
    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly ?Redis $redis = null,
        private readonly float $maxMemoryThresholdMb = 128.0
    ) {}

    /**
     * Evaluates all operational health checks and returns a detailed diagnostics payload.
     *
     * @return array{healthy: bool, checks: array<string, array{status: string, message: string}>}
     */
    public function check(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'memory' => $this->checkMemoryUsage(),
            'disk' => $this->checkDiskSpace(),
        ];

        $isHealthy = true;
        foreach ($checks as $result) {
            if ($result['status'] === 'DOWN') {
                $isHealthy = false;
                break;
            }
        }

        return [
            'healthy' => $isHealthy,
            'checks' => $checks,
        ];
    }

    /**
     * Verifies database connectivity using a lightweight probe query.
     *
     * @return array{status: string, message: string}
     */
    private function checkDatabase(): array
    {
        if ($this->pdo === null) {
            return ['status' => 'UP', 'message' => 'Database connection skipped (null)'];
        }

        try {
            $stmt = $this->pdo->query('SELECT 1');
            if ($stmt !== false) {
                return ['status' => 'UP', 'message' => 'Database connection responsive'];
            }
        } catch (Throwable $e) {
            return ['status' => 'DOWN', 'message' => 'Database query failed: ' . $e->getMessage()];
        }

        return ['status' => 'DOWN', 'message' => 'Database unresponsive'];
    }

    /**
     * Verifies Redis connection availability using a PING command.
     *
     * @return array{status: string, message: string}
     */
    private function checkRedis(): array
    {
        if ($this->redis === null) {
            return ['status' => 'UP', 'message' => 'Redis connection skipped (null)'];
        }

        try {
            if ($this->redis->ping() === true || $this->redis->ping() === '+PONG') {
                return ['status' => 'UP', 'message' => 'Redis server responsive'];
            }
        } catch (Throwable $e) {
            return ['status' => 'DOWN', 'message' => 'Redis ping failed: ' . $e->getMessage()];
        }

        return ['status' => 'DOWN', 'message' => 'Redis unresponsive'];
    }

    /**
     * Checks PHP memory consumption against the maximum configured threshold.
     *
     * @return array{status: string, message: string}
     */
    private function checkMemoryUsage(): array
    {
        $usedMb = memory_get_usage(true) / 1024 / 1024;
        if ($usedMb > $this->maxMemoryThresholdMb) {
            return [
                'status' => 'DOWN',
                'message' => sprintf('Memory usage (%.2f MB) exceeds threshold (%.2f MB)', $usedMb, $this->maxMemoryThresholdMb),
            ];
        }

        return [
            'status' => 'UP',
            'message' => sprintf('Memory usage nominal (%.2f MB / %.2f MB)', $usedMb, $this->maxMemoryThresholdMb),
        ];
    }

    /**
     * Checks free disk space in the current filesystem directory.
     *
     * @return array{status: string, message: string}
     */
    private function checkDiskSpace(): array
    {
        $freeSpaceBytes = disk_free_space(__DIR__);
        if ($freeSpaceBytes === false || $freeSpaceBytes < 50 * 1024 * 1024) {
            return ['status' => 'DOWN', 'message' => 'Disk space critically low (< 50MB free)'];
        }

        return ['status' => 'UP', 'message' => sprintf('Free disk space healthy (%.2f MB available)', $freeSpaceBytes / 1024 / 1024)];
    }
}