<?php

declare(strict_types=1);

namespace Phue\Tests\Benchmark;

use Phue\Core\Container;
use Phue\Core\Application;
use Phue\Core\Request;

/**
 * Automated High-Performance Benchmark Suite for Phue Engine.
 *
 * Measures Container service resolution speeds and full End-to-End Kernel throughput (RPS).
 *
 * @package Phue\Tests\Benchmark
 */
final class BenchmarkRunner
{
    /**
     * Benchmarks Container dependency resolution speed in nanoseconds per operation.
     *
     * @param int $iterations Number of resolution iterations (default: 10,000).
     * @return array{iterations: int, total_seconds: float, ns_per_op: float}
     */
    public function benchmarkContainerResolution(int $iterations = 10000): array
    {
        $container = new Container();
        $container->bind('service', static fn(): \stdClass => new \stdClass());

        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $container->make('service');
        }
        $endTime = microtime(true);

        $totalTime = $endTime - $startTime;
        $nsPerOp = ($totalTime / $iterations) * 1_000_000_000;

        return [
            'iterations' => $iterations,
            'total_seconds' => round($totalTime, 6),
            'ns_per_op' => round($nsPerOp, 2),
        ];
    }

    /**
     * Measures full End-to-End Kernel throughput in Requests Per Second (RPS) through $app->handle($request).
     *
     * @param Application $app Instantiated Phue Application instance with routes configured.
     * @param string $uri Route to exercise. Must exist, otherwise the measurement reports
     *                     the cost of a 404 instead of the cost of the route.
     * @param int $iterations Benchmark execution iterations (default: 10,000).
     * @return array{iterations: int, rps: float, total_seconds: float}
     */
    public function benchmarkKernelThroughput(Application $app, int $iterations = 10000, string $uri = '/health/live'): array
    {
        // Request's constructor is (method, uri) — the reverse order benchmarks a 404.
        $request = new Request('GET', $uri);

        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $app->handle($request);
        }
        $endTime = microtime(true);

        $totalTime = $endTime - $startTime;
        $rps = $totalTime > 0 ? $iterations / $totalTime : 0;

        return [
            'iterations' => $iterations,
            'rps' => round($rps, 2),
            'total_seconds' => round($totalTime, 6),
        ];
    }
}