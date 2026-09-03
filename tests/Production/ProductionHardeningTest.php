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
}