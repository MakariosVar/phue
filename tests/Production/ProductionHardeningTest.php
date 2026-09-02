<?php

declare(strict_types=1);

namespace Phue\Tests\Production;

use PHPUnit\Framework\TestCase;
use Phue\Core\Health\HealthChecker;
use Phue\Core\Security\SecurityMiddleware;
use Phue\Core\Response;
use Phue\Tests\Benchmark\BenchmarkRunner;

/**
 * Unit tests for Phase 12 Health Checks, Security Middleware, and Benchmark Suite.
 *
 * @package Phue\Tests\Production
 */
final class ProductionHardeningTest extends TestCase
{
    /**
     * Tests HealthChecker execution when no external services are attached.
     *
     * @return void
     */
    public function testHealthCheckerBaseline(): void
    {
        $checker = new HealthChecker();
        $report = $checker->check();

        $this->assertTrue($report['healthy']);
        $this->assertSame('UP', $report['checks']['memory']['status']);
        $this->assertSame('UP', $report['checks']['disk']['status']);
    }

    /**
     * Tests application of OWASP security headers by SecurityMiddleware.
     *
     * @return void
     */
    public function testSecurityMiddlewareHeaders(): void
    {
        $middleware = new SecurityMiddleware();
        $response = new Response('OK', 200, []);

        $hardened = $middleware->handle($response);
        $headers = $hardened->getHeaders();

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('1; mode=block', $headers['X-XSS-Protection']);
        $this->assertStringContainsString('max-age=31536000', $headers['Strict-Transport-Security']);
    }

    /**
     * Tests BenchmarkRunner Container and HTTP execution without exceptions.
     *
     * @return void
     */
    public function testBenchmarkRunner(): void
    {
        $runner = new BenchmarkRunner();

        $containerMetrics = $runner->benchmarkContainerResolution(100);
        $this->assertSame(100, $containerMetrics['iterations']);
        $this->assertGreaterThan(0, $containerMetrics['ns_per_op']);

        $httpMetrics = $runner->benchmarkHttpThroughput(100);
        $this->assertSame(100, $httpMetrics['iterations']);
        $this->assertGreaterThan(0, $httpMetrics['rps']);
    }
}