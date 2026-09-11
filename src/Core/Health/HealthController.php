<?php

declare(strict_types=1);

namespace Phue\Core\Health;

use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Core\Database\Connection;
use Phue\Core\Database\Drivers\Abstract\PdoDriverInterface;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Routing\Attribute\Route;
use PDO;
use Throwable;

/**
 * Enterprise Health Check & Readiness Endpoint Controller.
 *
 * Provides liveness and readiness probe routes for orchestrators (e.g., Kubernetes).
 *
 * @package Phue\Core\Health
 */
final class HealthController
{
    /**
     * Initialize controller with the dependency injection container.
     *
     * @param Container $container Central application DI container instance.
     */
    public function __construct(
        private readonly Container $container
    ) {}

    /**
     * Liveness probe endpoint.
     *
     * Indicates whether the web server process is alive and receiving HTTP traffic.
     *
     * @param Request $request Incoming HTTP request object.
     * 
     * @return Response Standard JSON response with process status and current server timestamp.
     */
    #[Route('/health/live', ['GET'])]
    public function liveness(Request $request): Response
    {
        return Response::json([
            'status' => 'UP',
            'timestamp' => time(),
        ]);
    }

    /**
     * Readiness probe endpoint.
     *
     * Checks dependent services (Database PDO connection, Redis cache instance)
     * to ensure the application is capable of handling user requests.
     *
     * @param Request $request Incoming HTTP request object.
     * 
     * @return Response JSON response with detailed readiness states (HTTP 200 on healthy, HTTP 530 on failure).
     */
    #[Route('/health/ready', ['GET'])]
    public function readiness(Request $request): Response
    {
        $isDebug = false;

        // Safely resolve Config singleton using defensive resolution and explicit binding check
        try {
            if ($this->container->bound(Config::class)) {
                /** @var Config $config */
                $config = $this->container->get(Config::class);
                $isDebug = (bool) $config->get('app.debug', false);
            } elseif ($this->container->bound('config')) {
                /** @var Config $config */
                $config = $this->container->get('config');
                $isDebug = (bool) $config->get('app.debug', false);
            }
        } catch (Throwable) {
            $isDebug = false;
        }

        $pdo = $this->resolvePdo();
        
        $redis = null;
        if ($this->container->bound('redis')) {
            try {
                $redis = $this->container->get('redis');
            } catch (Throwable) {
                $redis = null;
            }
        }

        $dbStatus = 'SKIPPED';
        $dbError = null;
        if ($pdo !== null) {
            try {
                $pdo->query('SELECT 1');
                $dbStatus = 'UP';
            } catch (Throwable $e) {
                $dbStatus = 'DOWN';
                $dbError = $isDebug ? $e->getMessage() : 'Database connection failed';
            }
        }

        $redisStatus = 'SKIPPED';
        $redisError = null;
        if ($redis !== null) {
            try {
                $redis->ping();
                $redisStatus = 'UP';
            } catch (Throwable $e) {
                $redisStatus = 'DOWN';
                $redisError = $isDebug ? $e->getMessage() : 'Redis connection failed';
            }
        }

        $isHealthy = $dbStatus !== 'DOWN' && $redisStatus !== 'DOWN';
        $statusCode = $isHealthy ? 200 : 530;

        $payload = [
            'status' => $isHealthy ? 'UP' : 'DOWN',
            'checks' => array_filter([
                'database' => $dbStatus !== 'SKIPPED' ? array_filter(['status' => $dbStatus, 'error' => $dbError]) : null,
                'redis'    => $redisStatus !== 'SKIPPED' ? array_filter(['status' => $redisStatus, 'error' => $redisError]) : null,
            ]),
        ];

        return Response::json($payload, $statusCode);
    }

    /**
     * Resolve underlying PDO instance from registered database connection driver.
     *
     * Checks if Connection is explicitly bound, avoiding autowiring errors on unbound setups.
     *
     * @return PDO|null Active PDO connection instance or null if unavailable.
     */
    private function resolvePdo(): ?PDO
    {
        if (!$this->container->bound(Connection::class)) {
            return null;
        }

        try {
            /** @var Connection $connection */
            $connection = $this->container->get(Connection::class);
            $driver = $connection->getDriver();

            if ($driver instanceof PdoDriverInterface) {
                return $driver->getPdo();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}