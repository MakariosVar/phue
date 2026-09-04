<?php

declare(strict_types=1);

namespace Phue\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Phue\Cache\EdgeKvCache;

/**
 * Full PSR-16 compliance tests for EdgeKvCache.
 *
 * @package Phue\Tests\Cache
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class EdgeKvCacheTest extends TestCase
{
    public function testSetAndGetSingleItem(): void
    {
        $cache = new EdgeKvCache();
        $this->assertFalse($cache->has('missing'));

        $cache->set('key', 'value');
        $this->assertTrue($cache->has('key'));
        $this->assertSame('value', $cache->get('key'));
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $cache = new EdgeKvCache();
        $this->assertSame('fallback', $cache->get('missing', 'fallback'));
    }

    public function testDeleteRemovesItem(): void
    {
        $cache = new EdgeKvCache();
        $cache->set('to_delete', 'gone');
        $cache->delete('to_delete');
        $this->assertFalse($cache->has('to_delete'));
    }

    public function testDeleteReturnsTrueForMissingKey(): void
    {
        $cache = new EdgeKvCache();
        $this->assertTrue($cache->delete('nonexistent'));
    }

    public function testClearEmptiesAllItems(): void
    {
        $cache = new EdgeKvCache();
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->clear();
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    public function testHasReturnsFalseForMissingKeys(): void
    {
        $cache = new EdgeKvCache();
        $this->assertFalse($cache->has('nothing'));
    }

    public function testGetMultipleReturnsDefaultsForMissingKeys(): void
    {
        $cache = new EdgeKvCache();
        $cache->set('present', 'p');

        $result = $cache->getMultiple(['present', 'absent'], 'default');

        $this->assertSame('p', $result['present']);
        $this->assertSame('default', $result['absent']);
    }

    public function testSetMultipleStoresMultipleKeys(): void
    {
        $cache = new EdgeKvCache();
        $cache->setMultiple(['x' => 1, 'y' => 2]);

        $this->assertSame(1, $cache->get('x'));
        $this->assertSame(2, $cache->get('y'));
    }

    public function testDeleteMultipleRemovesMultipleKeys(): void
    {
        $cache = new EdgeKvCache();
        $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
        $cache->deleteMultiple(['a', 'c']);

        $this->assertFalse($cache->has('a'));
        $this->assertTrue($cache->has('b'));
        $this->assertFalse($cache->has('c'));
    }

    public function testDeleteMultipleHandlesMissingKeys(): void
    {
        $cache = new EdgeKvCache();
        $cache->set('only', 1);

        $this->assertTrue($cache->deleteMultiple(['missing', 'only']));
        $this->assertFalse($cache->has('missing'));
        $this->assertFalse($cache->has('only'));
    }

    public function testClearOnEmptyCacheDoesNotThrow(): void
    {
        $cache = new EdgeKvCache();
        $this->assertTrue($cache->clear());
    }
}