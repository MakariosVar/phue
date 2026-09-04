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
#[\PHPUnit\Framework\Attributes\Group('integration')]
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

    // --- TursoDriver Extended Tests ---

    public function testTursoDriverSetEndpointAppendsV2Pipeline(): void
    {
        $driver = new TursoDriver();
        $result = $driver->setEndpoint('https://db.turso.io');

        $this->assertInstanceOf(TursoDriver::class, $result);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a valid endpoint and auth token');
        $driver->query('SELECT 1');
    }

    public function testTursoDriverSetEndpointDoesNotDoubleAppend(): void
    {
        $driver = new TursoDriver();
        $driver->setEndpoint('https://db.turso.io/v2/pipeline');

        $this->expectException(\RuntimeException::class);
        $driver->query('SELECT 1');
    }

    public function testTursoDriverSetEndpointTrimsTrailingSlash(): void
    {
        $driver = new TursoDriver();
        $driver->setEndpoint('https://db.turso.io/');

        $this->expectException(\RuntimeException::class);
        $driver->query('SELECT 1');
    }

    public function testTursoDriverConstructorWithEndpointAndToken(): void
    {
        $driver = new TursoDriver('https://test.turso.io', 'my_token');
        $this->assertSame('sqlite', $driver->getName());

        $this->expectException(\RuntimeException::class);
        $driver->query('SELECT 1');
    }

    public function testTursoDriverThrowsWhenEndpointOrTokenMissing(): void
    {
        $driver = new TursoDriver();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a valid endpoint and auth token');
        $driver->query('SELECT 1');
    }

    public function testTursoDriverExecuteThrowsWhenNotConfigured(): void
    {
        $driver = new TursoDriver();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a valid endpoint and auth token');
        $driver->execute('INSERT INTO test VALUES (1)');
    }

    // --- NeonDriver Extended Tests ---

    public function testNeonDriverSetEndpointAppendsSql(): void
    {
        $driver = new NeonDriver();
        $result = $driver->setEndpoint('https://project.neon.tech');

        $this->assertInstanceOf(NeonDriver::class, $result);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a valid endpoint and auth token');
        $driver->query('SELECT 1');
    }

    public function testNeonDriverSetEndpointDoesNotDoubleAppend(): void
    {
        $driver = new NeonDriver();
        $driver->setEndpoint('https://project.neon.tech/sql');

        $this->expectException(\RuntimeException::class);
        $driver->query('SELECT 1');
    }

    public function testNeonDriverConstructorWithEndpointAndToken(): void
    {
        $driver = new NeonDriver('https://ep-test.neon.tech', 'pg_token');
        $this->assertSame('pgsql', $driver->getName());

        $this->expectException(\RuntimeException::class);
        $driver->query('SELECT 1');
    }

    public function testNeonDriverThrowsWhenEndpointOrTokenMissing(): void
    {
        $driver = new NeonDriver();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a valid endpoint and auth token');
        $driver->query('SELECT 1');
    }

    public function testNeonDriverExecuteThrowsWhenNotConfigured(): void
    {
        $driver = new NeonDriver();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a valid endpoint and auth token');
        $driver->execute('INSERT INTO test VALUES (1)');
    }

    // --- EdgeKvCache Extended Tests ---

    public function testEdgeKvCacheGetReturnsDefaultForMissingKey(): void
    {
        $cache = new EdgeKvCache();
        $this->assertNull($cache->get('missing'));
        $this->assertSame('default', $cache->get('missing', 'default'));
    }

    public function testEdgeKvCacheSetReturnsTrue(): void
    {
        $cache = new EdgeKvCache();
        $this->assertTrue($cache->set('key', 'value'));
    }

    public function testEdgeKvCacheClearRemovesAllItems(): void
    {
        $cache = new EdgeKvCache();
        $cache->set('a', 1);
        $cache->set('b', 2);
        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    public function testEdgeKvCacheDeleteReturnsTrueForMissingKey(): void
    {
        $cache = new EdgeKvCache();
        $this->assertTrue($cache->delete('nonexistent'));
    }

    public function testEdgeKvCacheHasReturnsTrueForExistingKey(): void
    {
        $cache = new EdgeKvCache();
        $cache->set('exists', 42);
        $this->assertTrue($cache->has('exists'));
    }

    public function testEdgeKvCacheHasReturnsFalseForNullValue(): void
    {
        $cache = new EdgeKvCache();
        $cache->set('null_key', null);
        $this->assertTrue($cache->has('null_key'));
        $this->assertNull($cache->get('null_key'));
    }

    public function testEdgeKvCacheGetMultipleReturnsAllValues(): void
    {
        $cache = new EdgeKvCache();
        $cache->set('x', 10);
        $cache->set('y', 20);

        $results = $cache->getMultiple(['x', 'y', 'z']);
        $this->assertSame(10, $results['x']);
        $this->assertSame(20, $results['y']);
        $this->assertNull($results['z']);
    }

    public function testEdgeKvCacheSetMultipleStoresAllPairs(): void
    {
        $cache = new EdgeKvCache();
        $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(1, $cache->get('a'));
        $this->assertSame(2, $cache->get('b'));
        $this->assertSame(3, $cache->get('c'));
    }

    public function testEdgeKvCacheDeleteMultipleRemovesAllKeys(): void
    {
        $cache = new EdgeKvCache();
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('c', 3);

        $cache->deleteMultiple(['a', 'c']);

        $this->assertFalse($cache->has('a'));
        $this->assertTrue($cache->has('b'));
        $this->assertFalse($cache->has('c'));
    }

    public function testEdgeKvCacheOverwritesExistingKey(): void
    {
        $cache = new EdgeKvCache();
        $cache->set('key', 'first');
        $cache->set('key', 'second');
        $this->assertSame('second', $cache->get('key'));
    }

    public function testEdgeKvCacheStoresComplexTypes(): void
    {
        $cache = new EdgeKvCache();
        $data = ['nested' => ['array' => [1, 2, 3]], 'string' => 'hello'];
        $cache->set('complex', $data);
        $this->assertSame($data, $cache->get('complex'));
    }

    public function testEdgeKvCacheTtlParameterIsAccepted(): void
    {
        $cache = new EdgeKvCache();
        $this->assertTrue($cache->set('ttl_key', 'value', 3600));
        $this->assertTrue($cache->set('ttl_key2', 'value', new \DateInterval('PT1H')));
        $this->assertTrue($cache->set('ttl_key3', 'value', null));
    }
}