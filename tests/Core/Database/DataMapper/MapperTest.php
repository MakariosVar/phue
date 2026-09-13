<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database\DataMapper;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\DataMapper\Entity;
use Phue\Core\Database\DataMapper\Mapper;
use Phue\Core\Database\DataMapper\UnitOfWork;

class MapperProduct extends Entity
{
    public ?int $id = null;
    public string $name = '';
    public ?string $stockLevel = null;
    public string $isActive = '0';
    public ?string $legacyField = null;
    public ?string $untouched;

    public static function tableName(): string
    {
        return 'mapper_products';
    }
}

#[\PHPUnit\Framework\Attributes\Group('integration')]
class MapperTest extends TestCase
{
    private Connection $connection;
    private Mapper $mapper;
    private UnitOfWork $uow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new Connection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->connection->execute(
            'CREATE TABLE mapper_products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, stock_level TEXT, is_active TEXT, legacy_field TEXT)'
        );
        $this->mapper = new Mapper();
        $this->uow = new UnitOfWork($this->connection, $this->mapper);
    }

    public function testHydratePopulatesCamelCasePropertiesFromSnakeCaseRow(): void
    {
        $entity = $this->mapper->hydrate(
            ['id' => 7, 'name' => 'Widget', 'stock_level' => 'L2', 'is_active' => '1'],
            MapperProduct::class,
            $this->uow,
        );

        $this->assertSame(7, $entity->id);
        $this->assertSame('Widget', $entity->name);
        $this->assertSame('L2', $entity->stockLevel);
        $this->assertSame('1', $entity->isActive);
    }

    public function testHydrateRegistersEntityInIdentityMap(): void
    {
        $this->mapper->hydrate(
            ['id' => 3, 'name' => 'Gadget', 'is_active' => '1'],
            MapperProduct::class,
            $this->uow,
        );

        $this->assertSame(
            $this->uow->getFromIdentityMap('mapper_products', 3)::class,
            MapperProduct::class,
        );
        $this->assertSame(
            3,
            $this->uow->getFromIdentityMap('mapper_products', 3)->id,
        );
        $this->assertInstanceOf(MapperProduct::class, $this->uow->getFromIdentityMap('mapper_products', 3));
    }

    public function testHydrateWithoutPkDoesNotRegisterIdentity(): void
    {
        $this->mapper->hydrate(
            ['name' => 'Orphan', 'is_active' => '0'],
            MapperProduct::class,
            $this->uow,
        );

        $this->assertNull($this->uow->getFromIdentityMap('mapper_products', 0));
    }

    public function testHydrateSkipsColumnsNotPresentInRowLeavingDefaults(): void
    {
        $entity = $this->mapper->hydrate(
            ['id' => 9, 'name' => 'Partial'],
            MapperProduct::class,
            $this->uow,
        );

        $this->assertNull($entity->stockLevel);
        $this->assertSame('0', $entity->isActive);
    }

    public function testHydrateLeavesUnrelatedColumnsUntouched(): void
    {
        $entity = $this->mapper->hydrate(
            ['id' => 11, 'name' => 'X', 'unknown_column' => 'ghost'],
            MapperProduct::class,
            $this->uow,
        );

        $this->assertSame('X', $entity->name);
        $this->assertNull($entity->legacyField);
    }

    public function testDehydrateConvertsCamelCaseToSnakeCase(): void
    {
        $entity = new MapperProduct();
        $entity->id = 5;
        $entity->name = 'Betty';
        $entity->stockLevel = 'L1';

        $this->assertSame(
            [
                'id'          => 5,
                'name'        => 'Betty',
                'stock_level' => 'L1',
                'is_active'   => '0',
                'legacy_field' => null,
            ],
            $this->mapper->dehydrate($entity),
        );
    }

    public function testDehydrateIncludesExplicitNullDefaultsButSkipsUninitialized(): void
    {
        $entity = new MapperProduct();
        $entity->id = 2;
        $entity->name = 'Skip';
        $entity->isActive = '1';

        $data = $this->mapper->dehydrate($entity);

        $this->assertArrayHasKey('stock_level', $data, '=null default is initialized and must be included');
        $this->assertNull($data['stock_level']);
        $this->assertArrayHasKey('legacy_field', $data);
        $this->assertNull($data['legacy_field']);
        $this->assertArrayNotHasKey('untouched', $data, 'Uninitialized property must be skipped');
        $this->assertSame(2, $data['id']);
        $this->assertSame('Skip', $data['name']);
    }

    public function testToSnakeCaseHandlesAcronyms(): void
    {
        $this->assertSame('user_id', $this->mapper->toSnakeCase('userId'));
        $this->assertSame('api_key', $this->mapper->toSnakeCase('apiKey'));
        $this->assertSame('url', $this->mapper->toSnakeCase('url'));
    }

    public function testToCamelCaseHandlesSnakeInput(): void
    {
        $this->assertSame('stockLevel', $this->mapper->toCamelCase('stock_level'));
        $this->assertSame('userId', $this->mapper->toCamelCase('user_id'));
        $this->assertSame('url', $this->mapper->toCamelCase('url'));
    }

    public function testSnakeCamelRoundTripIsStable(): void
    {
        foreach (['stockLevel', 'apiKey', 'isActive'] as $camel) {
            $poisoned = $this->mapper->toCamelCase($this->mapper->toSnakeCase($camel));
            $this->assertSame($camel, $poisoned, "Round trip failed for {$camel}");
        }
    }

    public function testHydrateUsesSameMapperInstanceForSnapshot(): void
    {
        $this->connection->execute(
            "INSERT INTO mapper_products (id, name, stock_level, is_active) VALUES (42, 'Snapshot', 'P1', '1')"
        );

        $entity = $this->mapper->hydrate(
            ['id' => 42, 'name' => 'Snapshot', 'stock_level' => 'P1', 'is_active' => '1'],
            MapperProduct::class,
            $this->uow,
        );

        $this->assertInstanceOf(MapperProduct::class, $entity);

        $entity->stockLevel = 'P2';
        $this->uow->commit();

        $result = $this->connection->query('SELECT stock_level FROM mapper_products WHERE id = 42')->fetch();
        $this->assertSame('P2', $result['stock_level']);
    }
}