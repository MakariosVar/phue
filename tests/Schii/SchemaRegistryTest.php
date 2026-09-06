<?php

declare(strict_types=1);

namespace Phue\Tests\Schii;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Schii\Attribute\Field;
use Phue\Schii\Attribute\UI;
use Phue\Schii\Schema\ModelSchema;
use Phue\Schii\Schema\SchemaRegistry;
use Psr\SimpleCache\CacheInterface;

#[UI(label: 'Tagged Cats', icon: 'cat', layout: 'table')]
class TaggedCat extends BaseActiveRecord
{
    protected string $table = 'tagged_cats';

    #[Field(type: 'text', label: 'Cat Name', searchable: true)]
    public string $name = '';
}

class PlainRegistryModel extends BaseActiveRecord
{
    protected string $table = 'plain_registry_models';

    #[Field(type: 'text')]
    public string $some_field = '';
}

/**
 * SchemaRegistry: parsing, label humanization, cache layers (L1 memory,
 * L2 persistent) and cache invalidation.
 *
 * @package Phue\Tests\Schii
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class SchemaRegistryTest extends TestCase
{
    public function testRegistryUsesUiAttributeLabelAndBuildsPermissions(): void
    {
        $registry = new SchemaRegistry();
        $schema = $registry->get(TaggedCat::class);

        $this->assertInstanceOf(ModelSchema::class, $schema);
        $this->assertSame('Tagged Cats', $schema->label);
        $this->assertSame('cat', $schema->icon);
        $this->assertSame('table', $schema->layout);
        $this->assertSame('Cat Name', $schema->fields['name']->label);

        $this->assertSame('admin.taggedcat.create', $schema->permissions['create']);
        $this->assertSame('admin.taggedcat.read', $schema->permissions['read']);
        $this->assertSame('admin.taggedcat.update', $schema->permissions['update']);
        $this->assertSame('admin.taggedcat.delete', $schema->permissions['delete']);
    }

    public function testRegistryHumanizesEntityAndFieldNamesWithoutAttributes(): void
    {
        $registry = new SchemaRegistry();
        $schema = $registry->get(PlainRegistryModel::class);

        $this->assertSame('Plain Registry Model', $schema->label);
        $this->assertSame('Some Field', $schema->fields['some_field']->label);
    }

    public function testMemoryCacheHitReturnsSameInstanceAndClearInvalidates(): void
    {
        $registry = new SchemaRegistry();

        $first = $registry->get(TaggedCat::class);
        $second = $registry->get(TaggedCat::class);
        $this->assertSame($first, $second, 'L1 memory cache must return the same instance');

        $registry->clearMemoryCache();
        $third = $registry->get(TaggedCat::class);
        $this->assertNotSame($first, $third, 'clearMemoryCache() must force a re-parse');
        $this->assertSame('Tagged Cats', $third->label);
    }

    public function testPersistentCacheLayerIsUsedOnHit(): void
    {
        if (!interface_exists(CacheInterface::class)) {
            $this->markTestSkipped('psr/simple-cache is not installed; L2 cache layer untestable.');
        }

        $cache = new ArraySimpleCache();
        $registry = new SchemaRegistry(null, $cache);

        $first = $registry->get(TaggedCat::class);
        $this->assertNotEmpty($cache->keys(), 'Parsed schema must be stored in the persistent cache');

        // Store a fresh ModelSchema in the L2 cache to prove the hit path
        // returns the cached entry directly instead of re-running reflection.
        $cacheKey = 'schii_schema_' . md5(TaggedCat::class);
        $cachedSchema = new ModelSchema(
            entity: 'TaggedCat',
            className: TaggedCat::class,
            label: 'Cached Cat',
            icon: '',
            layout: '',
            description: null,
            fields: [],
            permissions: []
        );
        $cache->set($cacheKey, $cachedSchema);

        $registryWithSameCache = new SchemaRegistry(null, $cache);
        $cached = $registryWithSameCache->get(TaggedCat::class);
        $this->assertInstanceOf(ModelSchema::class, $cached);
        $this->assertSame('Cached Cat', $cached->label);
    }
}

/**
 * Minimal PSR-16 in-memory cache (only the members SchemaRegistry touches).
 *
 * @package Phue\Tests\Schii
 */
final class ArraySimpleCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    /** @return array<string> */
    public function keys(): array
    {
        return array_keys($this->store);
    }

    public function poison(mixed $value): void
    {
        $this->store = array_fill_keys($this->keys(), $value);
    }
}