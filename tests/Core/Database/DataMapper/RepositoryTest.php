<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database\DataMapper;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\DataMapper\Entity;
use Phue\Core\Database\DataMapper\Mapper;
use Phue\Core\Database\DataMapper\Repository;
use Phue\Core\Database\DataMapper\UnitOfWork;

class RepoProduct extends Entity
{
    public ?int $id = null;
    public string $name = '';
    public float $price = 0.0;

    public static function tableName(): string
    {
        return 'repo_products';
    }
}

class RepoCustomer extends Entity
{
    public ?string $customerCode = null;
    public string $fullName = '';

    public static function tableName(): string
    {
        return 'repo_customers';
    }

    public static function primaryKey(): string
    {
        return 'customer_code';
    }
}

#[\PHPUnit\Framework\Attributes\Group('integration')]
class RepositoryTest extends TestCase
{
    private Connection $connection;
    private Mapper $mapper;
    private UnitOfWork $uow;
    private Repository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new Connection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->connection->execute(
            'CREATE TABLE repo_products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, price REAL NOT NULL DEFAULT 0)'
        );
        $this->connection->execute(
            'CREATE TABLE repo_customers (customer_code TEXT PRIMARY KEY, full_name TEXT NOT NULL)'
        );
        $this->mapper = new Mapper();
        $this->uow = new UnitOfWork($this->connection, $this->mapper);
        $this->repo = new Repository($this->connection, $this->uow, $this->mapper, RepoProduct::class);
    }

    public function testFindByIdReturnsNewEntityForMissingRow(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    public function testFindByIdHydratesRow(): void
    {
        $this->connection->execute("INSERT INTO repo_products (name, price) VALUES ('Widget', 9.99)");

        $entity = $this->repo->findById(1);

        $this->assertInstanceOf(RepoProduct::class, $entity);
        $this->assertSame(1, $entity->id);
        $this->assertSame('Widget', $entity->name);
    }

    public function testFindByIdReturnsSameInstanceOnSecondCall(): void
    {
        $this->connection->execute("INSERT INTO repo_products (name, price) VALUES ('Same', 1.00)");

        $first = $this->repo->findById(1);
        $second = $this->repo->findById(1);

        $this->assertSame($first, $second);
    }

    public function testFindAllHydratesEveryRow(): void
    {
        $this->connection->execute("INSERT INTO repo_products (name, price) VALUES ('A', 1), ('B', 2), ('C', 3)");

        $all = $this->repo->findAll();

        $this->assertCount(3, $all);
        $this->assertContainsOnlyInstancesOf(RepoProduct::class, $all);
    }

    public function testQueryBuilderReturnsRawRowsAndAllowsWhereFiltering(): void
    {
        $this->connection->execute("INSERT INTO repo_products (name, price) VALUES ('A', 1), ('B', 2)");

        $row = $this->repo->query()->where('name', '=', 'B')->first();

        $this->assertSame('B', $row['name']);
    }

    public function testPersistThenFindByIdRoundTrip(): void
    {
        $entity = new RepoProduct();
        $entity->name = 'Persisted';
        $entity->price = 12.5;

        $this->repo->persist($entity);
        $this->uow->commit();

        $fetched = $this->repo->findById($entity->id);

        $this->assertSame($entity, $fetched, 'Identity map must return the same instance');
    }

    public function testRemoveDeletesPersistedRow(): void
    {
        $entity = new RepoProduct();
        $entity->name = 'Gone';
        $this->repo->persist($entity);
        $this->uow->commit();

        $this->repo->remove($entity);
        $this->uow->commit();

        $this->assertNull($this->repo->findById($entity->id));
    }

    public function testCustomPrimaryKeyLookup(): void
    {
        $repo = new Repository($this->connection, $this->uow, $this->mapper, RepoCustomer::class);
        $this->connection->execute(
            "INSERT INTO repo_customers (customer_code, full_name) VALUES ('C-100', 'Jane Doe')"
        );

        $customer = $repo->findById('C-100');

        $this->assertInstanceOf(RepoCustomer::class, $customer);
        $this->assertSame('C-100', $customer->customerCode);
        $this->assertSame('Jane Doe', $customer->fullName);
    }

    public function testCustomPrimaryKeyPersistRoundTrip(): void
    {
        $repo = new Repository($this->connection, $this->uow, $this->mapper, RepoCustomer::class);

        $customer = new RepoCustomer();
        $customer->customerCode = 'C-200';
        $customer->fullName = 'John Smith';

        $repo->persist($customer);
        $this->uow->commit();

        $this->assertSame($customer, $repo->findById('C-200'));
        $this->assertSame(
            'John Smith',
            $this->connection->query("SELECT full_name FROM repo_customers WHERE customer_code = 'C-200'")->fetch()['full_name'],
        );
    }

    public function testStringPkDirtyUpdatePersists(): void
    {
        $repo = new Repository($this->connection, $this->uow, $this->mapper, RepoCustomer::class);
        $customer = new RepoCustomer();
        $customer->customerCode = 'C-300';
        $customer->fullName = 'Before';
        $repo->persist($customer);
        $this->uow->commit();

        $customer->fullName = 'After';
        $this->uow->commit();

        $this->assertSame(
            'After',
            $this->connection->query("SELECT full_name FROM repo_customers WHERE customer_code = 'C-300'")->fetch()['full_name'],
        );
    }

    public function testStringPkDeletePersists(): void
    {
        $repo = new Repository($this->connection, $this->uow, $this->mapper, RepoCustomer::class);
        $customer = new RepoCustomer();
        $customer->customerCode = 'C-400';
        $customer->fullName = 'Doomed';
        $repo->persist($customer);
        $this->uow->commit();

        $repo->remove($customer);
        $this->uow->commit();

        $this->assertNull($repo->findById('C-400'));
    }

    public function testBatchStringPkPersistsRegisterDistinctIdentityKeys(): void
    {
        $repo = new Repository($this->connection, $this->uow, $this->mapper, RepoCustomer::class);

        foreach (['C-501', 'C-502', 'C-503'] as $i => $code) {
            $customer = new RepoCustomer();
            $customer->customerCode = $code;
            $customer->fullName = "Batch {$i}";
            $repo->persist($customer);
        }

        $this->uow->commit();

        $count = $this->connection->query('SELECT COUNT(*) as c FROM repo_customers')->fetch();
        $this->assertSame(3, (int) $count['c']);

        $this->assertSame($repo->findById('C-501')->fullName, 'Batch 0');
        $this->assertSame($repo->findById('C-502')->fullName, 'Batch 1');
        $this->assertSame($repo->findById('C-503')->fullName, 'Batch 2');
    }

    public function testHydrationFromRowsWithStringKeys(): void
    {
        $this->connection->execute("INSERT INTO repo_products (name, price) VALUES ('StrKey', 4.5)");

        $entity = $this->repo->findById(1);

        $this->assertSame('StrKey', $entity->name);
        $this->assertSame(4.5, $entity->price);
    }

    public function testTwoRepositoriesShareOneUnitOfWorkAndCommitAtomically(): void
    {
        $customerRepo = new Repository($this->connection, $this->uow, $this->mapper, RepoCustomer::class);

        $product = new RepoProduct();
        $product->name = 'SharedAlpha';
        $customer = new RepoCustomer();
        $customer->customerCode = 'S-1';
        $customer->fullName = 'Shared Jane';

        $this->repo->persist($product);
        $customerRepo->persist($customer);
        $this->uow->commit();

        $this->assertSame(
            'SharedAlpha',
            $this->connection->query('SELECT name FROM repo_products WHERE id = ' . (int) $product->id)->fetch()['name'],
        );
        $this->assertSame(
            'Shared Jane',
            $this->connection->query("SELECT full_name FROM repo_customers WHERE customer_code = 'S-1'")->fetch()['full_name'],
        );
        $this->assertSame($product, $this->repo->findById($product->id));
        $this->assertSame($customer, $customerRepo->findById('S-1'));
    }
}