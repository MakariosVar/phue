<?php

declare(strict_types=1);

namespace Phue\Tests\Production;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Health\HealthChecker;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Security\SecurityMiddleware;
use Phue\Tests\Benchmark\BenchmarkRunner;

/**
 * Unit tests covering Health Checking, Security Hardening, and Benchmark execution.
 *
 * @package Phue\Tests\Production
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ProductionHardeningTest extends TestCase
{
    /**
     * Verifies that HealthChecker baseline checks pass with valid environment options.
     *
     * @return void
     */
    public function testHealthCheckerBaseline(): void
    {
        $checker = new HealthChecker(basePath: sys_get_temp_dir());
        $report = $checker->check();

        $this->assertTrue($report['healthy']);
        $this->assertSame('UP', $report['checks']['memory']['status']);
        $this->assertSame('UP', $report['checks']['disk']['status']);
    }

    /**
     * Tests that SecurityMiddleware operates correctly within the Onion callable pipeline.
     *
     * @return void
     */
    public function testSecurityMiddlewareInOnionPipeline(): void
    {
        $middleware = new SecurityMiddleware();
        $request = new Request('/test', 'GET');

        $next = static fn(Request $req): Response => new Response('OK', 200);

        $response = $middleware($request, $next);
        $headers = $response->getHeaders();

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('1; mode=block', $headers['X-XSS-Protection']);
        $this->assertStringContainsString('max-age=31536000', $headers['Strict-Transport-Security']);
    }

    /**
     * Tests full End-to-End Kernel throughput metrics generation using BenchmarkRunner.
     *
     * @return void
     */
    public function testKernelBenchmark(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->getRouter()->get('/health/live', static fn(): Response => new Response('OK', 200));

        $runner = new BenchmarkRunner();
        $metrics = $runner->benchmarkKernelThroughput($app, 100);

        $this->assertSame(100, $metrics['iterations']);
        $this->assertGreaterThan(0, $metrics['rps']);
    }

    // --- HealthChecker Extended Tests ---

    public function testHealthCheckerSkipsDatabaseCheckWhenPdoIsNull(): void
    {
        $checker = new HealthChecker(pdo: null, basePath: sys_get_temp_dir());
        $report = $checker->check();

        $this->assertSame('UP', $report['checks']['database']['status']);
        $this->assertStringContainsString('skipped', $report['checks']['database']['message']);
    }

    public function testHealthCheckerSkipsRedisCheckWhenRedisIsNull(): void
    {
        $checker = new HealthChecker(redis: null, basePath: sys_get_temp_dir());
        $report = $checker->check();

        $this->assertSame('UP', $report['checks']['redis']['status']);
        $this->assertStringContainsString('skipped', $report['checks']['redis']['message']);
    }

    public function testHealthCheckerReportsDatabaseDownOnQueryFailure(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('query')
            ->willThrowException(new \PDOException('Connection refused'));

        $checker = new HealthChecker(pdo: $pdo, basePath: sys_get_temp_dir());
        $report = $checker->check();

        $this->assertFalse($report['healthy']);
        $this->assertSame('DOWN', $report['checks']['database']['status']);
        $this->assertStringContainsString('Connection refused', $report['checks']['database']['message']);
    }

    public function testHealthCheckerReportsDatabaseDownOnFalseReturn(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('query')->willReturn(false);

        $checker = new HealthChecker(pdo: $pdo, basePath: sys_get_temp_dir());
        $report = $checker->check();

        $this->assertFalse($report['healthy']);
        $this->assertSame('DOWN', $report['checks']['database']['status']);
    }

    public function testHealthCheckerReportsRedisDownOnPingFailure(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')
            ->willThrowException(new \RuntimeException('Connection lost'));

        $checker = new HealthChecker(redis: $redis, basePath: sys_get_temp_dir());
        $report = $checker->check();

        $this->assertFalse($report['healthy']);
        $this->assertSame('DOWN', $report['checks']['redis']['status']);
        $this->assertStringContainsString('Connection lost', $report['checks']['redis']['message']);
    }

    public function testHealthCheckerReportsRedisDownOnUnexpectedPingResponse(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('NOT_PONG');

        $checker = new HealthChecker(redis: $redis, basePath: sys_get_temp_dir());
        $report = $checker->check();

        $this->assertFalse($report['healthy']);
        $this->assertSame('DOWN', $report['checks']['redis']['status']);
    }

    public function testHealthCheckerReportsRedisUpOnPingTrue(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn(true);

        $checker = new HealthChecker(redis: $redis, basePath: sys_get_temp_dir());
        $report = $checker->check();

        $this->assertSame('UP', $report['checks']['redis']['status']);
    }

    public function testHealthCheckerReportsRedisUpOnPingPong(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('+PONG');

        $checker = new HealthChecker(redis: $redis, basePath: sys_get_temp_dir());
        $report = $checker->check();

        $this->assertSame('UP', $report['checks']['redis']['status']);
    }

    public function testHealthCheckerAllServicesHealthy(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $pdo->method('query')->willReturn($stmt);

        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('+PONG');

        $checker = new HealthChecker(
            pdo: $pdo,
            redis: $redis,
            basePath: sys_get_temp_dir()
        );
        $report = $checker->check();

        $this->assertTrue($report['healthy']);
        $this->assertSame('UP', $report['checks']['database']['status']);
        $this->assertSame('UP', $report['checks']['redis']['status']);
        $this->assertSame('UP', $report['checks']['memory']['status']);
        $this->assertSame('UP', $report['checks']['disk']['status']);
    }

    public function testHealthCheckerMemoryDownWhenThresholdExceeded(): void
    {
        $checker = new HealthChecker(maxMemoryThresholdMb: 0.0001, basePath: sys_get_temp_dir());
        $report = $checker->check();

        $this->assertFalse($report['healthy']);
        $this->assertSame('DOWN', $report['checks']['memory']['status']);
        $this->assertStringContainsString('exceeds threshold', $report['checks']['memory']['message']);
    }

    public function testHealthCheckerDiskDownWhenSpaceProbeFails(): void
    {
        $checker = new HealthChecker(basePath: '/nonexistent-phue-dir-xyz');
        $report = $checker->check();

        $this->assertFalse($report['healthy']);
        $this->assertSame('DOWN', $report['checks']['disk']['status']);
        $this->assertStringContainsString('critically low', $report['checks']['disk']['message']);
    }

    public function testHealthCheckerReturnsAllFourCheckCategories(): void
    {
        $checker = new HealthChecker(basePath: sys_get_temp_dir());
        $report = $checker->check();

        $this->assertArrayHasKey('healthy', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertArrayHasKey('database', $report['checks']);
        $this->assertArrayHasKey('redis', $report['checks']);
        $this->assertArrayHasKey('memory', $report['checks']);
        $this->assertArrayHasKey('disk', $report['checks']);
    }

    // --- SecurityMiddleware Extended Tests ---

    public function testSecurityMiddlewareSetsAllSixHeaders(): void
    {
        $middleware = new SecurityMiddleware();
        $request = new Request('/test', 'GET');

        $next = static fn(Request $req): Response => new Response('OK', 200);

        $response = $middleware($request, $next);
        $headers = $response->getHeaders();

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('1; mode=block', $headers['X-XSS-Protection']);
        $this->assertStringContainsString('max-age=31536000', $headers['Strict-Transport-Security']);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        $this->assertStringContainsString("default-src 'self'", $headers['Content-Security-Policy']);
    }

    public function testSecurityMiddlewareDoesNotModifyStatusCode(): void
    {
        $middleware = new SecurityMiddleware();
        $request = new Request('/api', 'GET');

        $next = static fn(Request $req): Response => new Response('Created', 201);

        $response = $middleware($request, $next);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testSecurityMiddlewarePreservesResponseContent(): void
    {
        $middleware = new SecurityMiddleware();
        $request = new Request('/test', 'GET');

        $next = static fn(Request $req): Response => new Response('payload', 200);

        $response = $middleware($request, $next);

        $this->assertSame('payload', $response->getContent());
    }

    public function testSecurityMiddlewarePreservesExistingHeaders(): void
    {
        $middleware = new SecurityMiddleware();
        $request = new Request('/test', 'GET');

        $next = static fn(Request $req): Response => new Response('OK', 200, ['X-Custom' => 'value']);

        $response = $middleware($request, $next);
        $headers = $response->getHeaders();

        $this->assertSame('value', $headers['X-Custom']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }
}