<?php

declare(strict_types=1);

namespace Phue\Tests\Production;

use Phue\Core\Application;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * Contract tests for the framework health-check endpoints.
 *
 * Locks the behavior of /health/live and /health/ready: liveness always
 * reports UP, readiness degrades gracefully to SKIPPED for services that are
 * not bound in the container (never 500), and both endpoints are covered by
 * the global security headers.
 */
class HealthEndpointContractTest extends TestCase
{
    use RestoresActiveRecordConnection;

    public function testLiveEndpointReportsUp(): void
    {
        $app = new Application('/app');
        $this->isolateApplicationDatabase($app);

        $response = $app->handle(new Request('GET', '/health/live'));
        $body = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('UP', $body['status']);
        $this->assertArrayHasKey('timestamp', $body);
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    public function testReadyEndpointSkipsUnboundServicesInsteadOf500(): void
    {
        $app = new Application(__DIR__ . '/_tmp_conftest');

        $response = $app->handle(new Request('GET', '/health/ready'));
        $body = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('UP', $body['status']);
        $this->assertSame([], $body['checks']);
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    public function testReadyEndpointIsRegisteredAsGetRoute(): void
    {
        $app = new Application(__DIR__ . '/_tmp_conftest');

        $response = $app->handle(new Request('POST', '/health/ready'));

        $this->assertSame(405, $response->getStatusCode());
    }

    public function testReadyEndpointReportsDownWhenBoundRedisFailsPing(): void
    {
        $app = new Application(__DIR__ . '/_tmp_conftest');
        $app->getContainer()->singleton('redis', static function (): object {
            return new class {
                public function ping(): never
                {
                    throw new \RuntimeException('redis is on fire');
                }
            };
        });

        $response = $app->handle(new Request('GET', '/health/ready'));
        $body = json_decode($response->getContent(), true);

        $this->assertSame(530, $response->getStatusCode());
        $this->assertSame('DOWN', $body['status']);
        $this->assertSame('DOWN', $body['checks']['redis']['status']);
        $this->assertArrayHasKey('error', $body['checks']['redis']);
    }

    public function testReadyEndpointReportsUpWhenBoundRedisResponds(): void
    {
        $app = new Application(__DIR__ . '/_tmp_conftest');
        $app->getContainer()->singleton('redis', static fn (): object => new class {
            public function ping(): string
            {
                return 'PONG';
            }
        });

        $response = $app->handle(new Request('GET', '/health/ready'));
        $body = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('UP', $body['status']);
        $this->assertSame('UP', $body['checks']['redis']['status']);
    }

    public function testReadyEndpointSkipsProbeWhenBoundConnectionDriverThrows(): void
    {
        $app = new Application(__DIR__ . '/_tmp_conftest');
        $app->getContainer()->singleton(\Phue\Core\Database\Connection::class, static function (): object {
            return new class {
                public function getDriver(): never
                {
                    throw new \RuntimeException('broken driver');
                }
            };
        });

        $response = $app->handle(new Request('GET', '/health/ready'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotContains('database', array_keys(json_decode($response->getContent(), true)['checks'] ?? []));
    }
}