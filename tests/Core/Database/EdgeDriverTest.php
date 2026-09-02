<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database;

use PHPUnit\Framework\TestCase;
use Phue\Cache\EdgeKvCache;
use Phue\Core\Container;
use Phue\Core\Database\Drivers\NeonDriver;
use Phue\Core\Database\Drivers\TursoDriver;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Server\WasmAdapter;

/**
 * Unit tests for Edge & WASM components including TursoDriver, NeonDriver, WasmAdapter, and EdgeKvCache.
 *
 * @package Phue\Tests\Core\Database
 */
final class EdgeDriverTest extends TestCase
{
    /**
     * Tests TursoDriver dialect identification and metadata.
     *
     * @return void
     */
    public function testTursoDriverMetadataAndName(): void
    {
        $driver = new TursoDriver('https://test-db.turso.io', 'test_token');
        $this->assertSame('sqlite', $driver->getName());
    }

    /**
     * Tests NeonDriver dialect identification and metadata.
     *
     * @return void
     */
    public function testNeonDriverMetadataAndName(): void
    {
        $driver = new NeonDriver('https://test-project.neon.tech', 'test_token');
        $this->assertSame('pgsql', $driver->getName());
    }

    /**
     * Tests WasmAdapter HTTP lifecycle processing and container scope flushing.
     *
     * @return void
     */
    public function testWasmAdapterLifecycleAndMemoryFlush(): void
    {
        $container = new Container();
        $adapter = new WasmAdapter($container);

        $env = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/edge',
            'HEADERS' => ['Content-Type' => 'application/json'],
            'BODY' => (string) json_encode(['hello' => 'wasm']),
        ];

        $result = $adapter->handleWasmRequest($env, function (Request $req) {
            return new Response('OK: ' . $req->getMethod(), 200, ['X-Edge-Header' => 'PhueWasm']);
        });

        $this->assertSame(200, $result['status']);
        $this->assertSame('PhueWasm', $result['headers']['X-Edge-Header']);
        $this->assertSame('OK: POST', $result['body']);
    }

    /**
     * Tests EdgeKvCache storage, retrieval, deletion, and clearing operations.
     *
     * @return void
     */
    public function testEdgeKvCacheOperations(): void
    {
        $cache = new EdgeKvCache();
        $this->assertFalse($cache->has('schema_user'));

        $cache->set('schema_user', ['id' => 'int', 'name' => 'string']);
        $this->assertTrue($cache->has('schema_user'));
        $this->assertSame('int', $cache->get('schema_user')['id']);

        $cache->delete('schema_user');
        $this->assertFalse($cache->has('schema_user'));
    }
}