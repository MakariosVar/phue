<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database\DataMapper;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\DataMapper\Entity;
use Phue\Core\Database\DataMapper\Mapper;
use Phue\Core\Database\DataMapper\UnitOfWork;

class UowProduct extends Entity
{
    public ?int $id = null;
    public string $name = '';
    public int $quantity = 0;
    public ?string $category = null;

    public static function tableName(): string
    {
        return 'uow_products';
    }
}

#[\PHPUnit\Framework\Attributes\Group('integration')]
class UnitOfWorkTest extends TestCase
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
            'CREATE TABLE uow_products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, quantity INTEGER NOT NULL DEFAULT 0, category TEXT)'
        );
        $this->mapper = new Mapper();
        $this->uow = new UnitOfWork($this->connection, $this->mapper);
    }

    public function testPersistNewEntityInsertsAndAssignsAutoIncrementId(): void
    {
        $entity = new UowProduct();
        $entity->name = 'Alpha';
        $entity->quantity = 3;

        $this->uow->registerNew($entity);
        $this->uow->commit();

        $this->assertNotNull($entity->id, 'Auto-increment id must be assigned back');
        $this->assertGreaterThan(0, $entity->id);

        $row = $this->connection->query('SELECT * FROM uow_products WHERE id = ' . (int) $entity->id)->fetch();
        $this->assertSame('Alpha', $row['name']);
        $this->assertSame(3, (int) $row['quantity']);
    }

    public function testPersistMarksNewEntityRegisteredInIdentityMap(): void
    {
        $entity = new UowProduct();
        $entity->name = 'Beta';

        $this->uow->registerNew($entity);
        $this->uow->commit();

        $this->assertSame(
            $entity,
            $this->uow->getFromIdentityMap('uow_products', $entity->id),
        );
    }

    public function testDirtyTrackingUpdatesOnlyChangedColumns(): void
    {
        $entity = new UowProduct();
        $entity->name = 'Gamma';
        $entity->quantity = 10;
        $entity->category = 'A';
        $this->uow->registerNew($entity);
        $this->uow->commit();

        $entity->quantity = 99;
        $this->uow->commit();

        $row = $this->connection->query('SELECT * FROM uow_products WHERE id = ' . (int) $entity->id)->fetch();
        $this->assertSame(99, (int) $row['quantity']);
        $this->assertSame('A', $row['category'], 'Unchanged column must not be touched');
    }

    public function testHydratedManagedEntityDirtyUpdatePersists(): void
    {
        $this->connection->execute("INSERT INTO uow_products (id, name, quantity, category) VALUES (7, 'Delta', 5, 'B')");

        $entity = $this->mapper->hydrate(
            ['id' => 7, 'name' => 'Delta', 'quantity' => 5, 'category' => 'B'],
            UowProduct::class,
            $this->uow,
        );

        $entity->category = null;
        $entity->quantity = 0;
        $this->uow->commit();

        $row = $this->connection->query('SELECT * FROM uow_products WHERE id = 7')->fetch();
        $this->assertNull($row['category']);
        $this->assertSame(0, (int) $row['quantity']);
    }

    public function testRegisterRemovedDeletesOnCommit(): void
    {
        $entity = new UowProduct();
        $entity->name = 'Epsilon';
        $this->uow->registerNew($entity);
        $this->uow->commit();

        $this->uow->registerRemoved($entity);
        $this->uow->commit();

        $row = $this->connection->query('SELECT * FROM uow_products WHERE id = ' . (int) $entity->id)->fetch();
        $this->assertFalse($row);
    }

    public function testRemoveNewlyPersistedEntityCancelsIt(): void
    {
        $entity = new UowProduct();
        $entity->name = 'Zeta';
        $this->uow->registerNew($entity);
        $this->uow->registerRemoved($entity);

        $this->uow->commit();

        $count = $this->connection->query('SELECT COUNT(*) as c FROM uow_products')->fetch();
        $this->assertSame(0, (int) $count['c']);
    }

    public function testCommitIsAtomicAndRollsBackOnError(): void
    {
        $this->connection->execute(
            'CREATE TABLE uow_unique (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE)'
        );

        $insertOk = $this->connection->execute(
            "INSERT INTO uow_unique (id, email) VALUES (1, 'a@example.com')"
        );
        $this->assertSame(1, $insertOk);

        $entity = new UowProduct();
        $entity->name = 'Rollback';
        $this->uow->registerNew($entity);

        $this->connection->execute('DROP TABLE uow_products');

        $this->expectException(\Throwable::class);
        $this->uow->commit();

        $count = $this->connection->query('SELECT COUNT(*) as c FROM uow_unique')->fetch();
        $this->assertSame(1, (int) $count['c'], 'Transaction must roll back the whole unit');
    }

    public function testRetryAfterFailedCommitPersistsNewEntity(): void
    {
        $entity = new UowProduct();
        $entity->name = 'Retry';
        $this->uow->registerNew($entity);

        $this->connection->execute('DROP TABLE uow_products');

        try {
            $this->uow->commit();
            $this->fail('First commit should throw');
        } catch (\Throwable) {
        }

        $this->connection->execute(
            'CREATE TABLE uow_products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, quantity INTEGER NOT NULL DEFAULT 0, category TEXT)'
        );

        $this->uow->commit();

        $row = $this->connection->query('SELECT * FROM uow_products WHERE name = \'Retry\'')->fetch();
        $this->assertSame('Retry', $row['name'], 'Entity must survive the failed attempt and be persisted on retry');
    }

    public function testCombinedCommitOfInsertUpdateAndDelete(): void
    {
        $inserted = new UowProduct();
        $inserted->name = 'CombinedNew';
        $this->uow->registerNew($inserted);

        $this->connection->execute("INSERT INTO uow_products (id, name, quantity, category) VALUES (50, 'CombinedOld', 1, 'X')");
        $updated = $this->mapper->hydrate(
            ['id' => 50, 'name' => 'CombinedOld', 'quantity' => 1, 'category' => 'X'],
            UowProduct::class,
            $this->uow,
        );

        $this->connection->execute("INSERT INTO uow_products (id, name, quantity, category) VALUES (51, 'CombinedDel', 2, 'Y')");
        $deleted = $this->mapper->hydrate(
            ['id' => 51, 'name' => 'CombinedDel', 'quantity' => 2, 'category' => 'Y'],
            UowProduct::class,
            $this->uow,
        );

        $updated->quantity = 100;
        $this->uow->registerRemoved($deleted);
        $this->uow->commit();

        $this->assertSame(
            100,
            (int) $this->connection->query('SELECT quantity FROM uow_products WHERE id = 50')->fetch()['quantity'],
        );
        $this->assertFalse(
            $this->connection->query('SELECT id FROM uow_products WHERE id = 51')->fetch(),
        );
        $this->assertSame(
            'CombinedNew',
            $this->connection->query('SELECT name FROM uow_products WHERE id = ' . (int) $inserted->id)->fetch()['name'],
        );
    }

    public function testCommitWithNoChangesDoesNothing(): void
    {
        $entity = new UowProduct();
        $entity->name = 'Noop';
        $this->uow->registerNew($entity);
        $this->uow->commit();

        $before = $this->connection->query('SELECT COUNT(*) as c FROM uow_products')->fetch();
        $this->uow->commit();

        $after = $this->connection->query('SELECT COUNT(*) as c FROM uow_products')->fetch();
        $this->assertSame((int) $before['c'], (int) $after['c']);
    }

    public function testMultiplePersistsInOneCommit(): void
    {
        $a = new UowProduct();
        $a->name = 'MultiA';
        $b = new UowProduct();
        $b->name = 'MultiB';
        $c = new UowProduct();
        $c->name = 'MultiC';

        $this->uow->registerNew($a);
        $this->uow->registerNew($b);
        $this->uow->registerNew($c);
        $this->uow->commit();

        $count = $this->connection->query('SELECT COUNT(*) as c FROM uow_products')->fetch();
        $this->assertSame(3, (int) $count['c']);

        foreach ([$a, $b, $c] as $entity) {
            $this->assertNotNull($entity->id);
        }
    }
}