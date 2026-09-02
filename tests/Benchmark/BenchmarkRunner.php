<?php

declare(strict_types=1);

namespace Phue\Tests\Benchmark;

use Phue\Core\Container;
use Phue\Core\Request;
use Phue\Core\Response;

/**
 * Automated High-Performance Benchmark Suite for Phue Engine.
 *
 * Measures Container resolution latency, HTTP Request/Response throughput (RPS),
 * and object allocation speed.
 *
 * @package Phue\Tests\Benchmark
 */
final class BenchmarkRunner
{
    /**
     * Benchmarks Container service resolution speed.
     *
     * @param int $iterations Number of resolution iterations (default: 10,000).
     * @return array{iterations: int, total_seconds: float, ns_per_op: float}
     */
    public function benchmarkContainerResolution(int $iterations = 10000): array
    {
        $container = new Container();
        $container->bind('service', fn() => new \stdClass());

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
     * Benchmarks HTTP Request/Response instantiation throughput (RPS).
     *
     * @param int $iterations Number of iterations (default: 50,000).
     * @return array{iterations: int, rps: float, total_seconds: float}
     */
    public function benchmarkHttpThroughput(int $iterations = 50000): array
    {
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $request = new Request(uri: '/api/v1/test', method: 'GET');
            $response = new Response('{"status":"ok"}', 200, ['Content-Type' => 'application/json']);

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