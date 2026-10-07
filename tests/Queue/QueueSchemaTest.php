<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Modules\Queue\Driver\DatabaseQueueDriver;
use Phue\Modules\Queue\Exception\SchemaException;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\Schema\QueueSchema;
use Phue\Tests\Support\SqliteFactory;

/**
 * Creating and upgrading the queue's own tables.
 *
 * Two things are being protected here. The first is that install() is safe to run against a live
 * database — it is the command an operator reaches for when something is already broken, and a
 * second call must not disturb jobs that are stored. The second is that it refuses to invent the
 * schema silently: nothing here creates a table on the way to pushing a job.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class QueueSchemaTest extends TestCase
{
    private Connection $connection;

    private QueueConfig $config;

    private QueueSchema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = SqliteFactory::memory();
        $this->config = QueueConfig::fromArray(['driver' => 'database', 'table' => 'queue_jobs']);
        $this->schema = new QueueSchema($this->connection, $this->config->table);
    }

    // ------------------------------------------------------------------ naming

    public function testTheTableNamesAreDerivedFromTheConfiguredOne(): void
    {
        $this->assertSame('queue_jobs', $this->schema->jobsTable());
        $this->assertSame('queue_jobs_failed', $this->schema->failedTable());
        $this->assertSame('queue_jobs_meta', $this->schema->metaTable());
    }

    public function testARenamedTableRenamesAllThree(): void
    {
        $schema = new QueueSchema($this->connection, 'jobs_2026');

        $this->assertSame('jobs_2026_failed', $schema->failedTable());
        $this->assertSame('jobs_2026_meta', $schema->metaTable());
    }

    /**
     * An index name is derived from the table name too, and two queues cannot share a database
     * without their indexes colliding.
     */
    public function testTheTablesAreListedTogether(): void
    {
        $this->assertSame(
            ['queue_jobs', 'queue_jobs_failed', 'queue_jobs_meta'],
            $this->schema->tables()
        );
    }

    // ------------------------------------------------------------------ installing

    public function testInstallingCreatesAllThreeTables(): void
    {
        $applied = $this->schema->install();

        $this->assertSame(['created'], $applied);
        $this->assertTrue($this->schema->hasTable('queue_jobs'));
        $this->assertTrue($this->schema->hasTable('queue_jobs_failed'));
        $this->assertTrue($this->schema->hasTable('queue_jobs_meta'));
    }

    public function testInstallingRecordsTheVersion(): void
    {
        $this->schema->install();

        $this->assertSame(QueueSchema::VERSION, $this->schema->installedVersion());
    }

    public function testNothingIsInstalledBeforeInstallIsCalled(): void
    {
        $this->assertNull($this->schema->installedVersion());
        $this->assertFalse($this->schema->hasTable('queue_jobs'));
    }

    public function testAnAbsentTableIsReportedAsAbsent(): void
    {
        $this->assertFalse($this->schema->hasTable('no_such_table'));
    }

    // ------------------------------------------------------------------ idempotence

    /**
     * The property that makes install() safe to run from a deploy script: it runs again, it
     * changes nothing, and it says so.
     */
    public function testInstallingTwiceIsANoOp(): void
    {
        $this->schema->install();

        $this->assertSame([], $this->schema->install());
    }

    public function testInstallingTwiceDoesNotDisturbStoredJobs(): void
    {
        $this->schema->install();
        $driver = new DatabaseQueueDriver($this->connection, $this->config);
        $driver->push(\Phue\Modules\Queue\JobEnvelope::forJob('Some\\Job', ['a' => 1], 'default'));

        $this->schema->install();

        $this->assertSame(1, $driver->stats()['pending']);
    }

    public function testInstallingTwiceDoesNotDuplicateTheVersionRecord(): void
    {
        $this->schema->install();
        $this->schema->install();
        $this->schema->install();

        $rows = $this->connection->query('SELECT COUNT(*) AS total FROM queue_jobs_meta')->fetchAll();

        $this->assertSame(1, (int) ((array) $rows[0])['total']);
    }

    // ------------------------------------------------------------------ dropping

    public function testDroppingRemovesEveryTable(): void
    {
        $this->schema->install();

        $dropped = $this->schema->drop();

        $this->assertSame(['queue_jobs_failed', 'queue_jobs_meta', 'queue_jobs'], $dropped);
        $this->assertFalse($this->schema->hasTable('queue_jobs'));
    }

    public function testDroppingAnUninstalledQueueIsHarmless(): void
    {
        $dropped = $this->schema->drop();

        $this->assertCount(3, $dropped);
        $this->assertNull($this->schema->installedVersion());
    }

    // ------------------------------------------------------------------ what the driver does about it

    /**
     * Failing loudly is the whole point. A driver that quietly created the table on the first push
     * would be running a schema migration from inside a request, in production, without anybody
     * having chosen that.
     */
    public function testADriverRefusesToWorkAgainstAnUninstalledTable(): void
    {
        $driver = new DatabaseQueueDriver($this->connection, $this->config);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/queue:install/');

        $driver->push(\Phue\Modules\Queue\JobEnvelope::forJob('Some\\Job', [], 'default'));
    }

    public function testTheRefusalNamesTheTableAndWhereItCameFrom(): void
    {
        $driver = new DatabaseQueueDriver($this->connection, $this->config);

        try {
            $driver->stats();
            $this->fail('Expected the driver to refuse.');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('queue_jobs', $e->getMessage());
            $this->assertStringContainsString('queue.table', $e->getMessage());
        }
    }

    /**
     * Layering, stated as a test: the driver stores whatever it is handed and never validates a
     * class name. Checking belongs to the manager, which is the only layer that knows what a job
     * is — and putting it in the driver would mean two drivers implementing the same allowlist.
     */
    public function testTheDriverStoresAJobWhoseClassItCannotCheck(): void
    {
        $this->schema->install();
        $driver = new DatabaseQueueDriver($this->connection, $this->config);

        $id = $driver->push(\Phue\Modules\Queue\JobEnvelope::forJob(
            'Phue\\Modules\\Queue\\NoSuchJob',
            [],
            'default'
        ));

        $this->assertNotSame('', $id);
        $this->assertSame(1, $driver->stats()['pending']);
    }

    /**
     * The manager is where the check lives, so a job that could never be run is refused at push
     * rather than sitting in the table until a worker meets it.
     */
    public function testTheManagerRefusesAJobWhoseClassIsNotLoadable(): void
    {
        $this->schema->install();
        $manager = new \Phue\Modules\Queue\QueueManager(
            new DatabaseQueueDriver($this->connection, $this->config),
            $this->config
        );

        $envelope = \Phue\Modules\Queue\JobEnvelope::forJob(
            'Phue\\Modules\\Queue\\NoSuchJob',
            [],
            'default'
        );

        $this->expectException(\Phue\Modules\Queue\Exception\QueuePayloadException::class);
        $this->expectExceptionMessageMatches('/does not exist or is not autoloadable/');

        $manager->hydrate($envelope);
    }

    public function testADriverRefusesAJobFromOutsideTheAllowedNamespaces(): void
    {
        $config = QueueConfig::fromArray([
            'driver' => 'database',
            'job_namespaces' => ['App\\\\Jobs'],
        ]);
        $this->schema->install();
        $manager = new \Phue\Modules\Queue\QueueManager(
            new DatabaseQueueDriver($this->connection, $config),
            $config
        );

        $this->expectException(\Phue\Modules\Queue\Exception\QueuePayloadException::class);

        $manager->push(new \Phue\Tests\Queue\Stubs\PlainJob());
    }

    // ------------------------------------------------------------------ columns

    /**
     * The claim query names these columns, and so does the ownership check. A missing one turns
     * into a runtime error in a worker rather than anything visible here, so the shape is pinned.
     *
     * @return array<string, array{0: string}>
     */
    public static function jobColumns(): array
    {
        $columns = [
            'queue', 'job_class', 'payload', 'status', 'attempts',
            'available_at', 'reserved_at', 'reserved_until', 'reserved_by',
            'completed_at', 'created_at',
        ];

        return array_combine($columns, array_map(static fn (string $c): array => [$c], $columns));
    }

    #[DataProvider('jobColumns')]
    public function testTheJobsTableHasTheColumnTheQueueNeeds(string $column): void
    {
        $this->schema->install();

        $columns = $this->columnsOf('queue_jobs');

        $this->assertContains($column, $columns);
    }

    /**
     * Without this index the claim degrades to a full scan on every attempt, which is the
     * difference between a queue that handles a burst and one that falls over during it.
     */
    public function testTheClaimIndexCoversTheColumnsTheClaimFiltersAndOrdersBy(): void
    {
        $this->schema->install();

        $indexes = $this->indexesOf('queue_jobs');

        $this->assertContains('queue_jobs_claim_idx', $indexes);
        $this->assertContains('queue_jobs_lease_idx', $indexes);
    }

    public function testTheFailedTableCarriesThePayloadSoAJobCanBeReplayed(): void
    {
        $this->schema->install();

        $columns = $this->columnsOf('queue_jobs_failed');

        foreach (['queue', 'job_class', 'payload', 'attempts', 'exception', 'failed_at'] as $column) {
            $this->assertContains($column, $columns, $column);
        }
    }

    /**
     * Column names, read from the catalogue.
     *
     * Asked of the database rather than of the class that wrote the DDL, because a test that
     * asserted against the same list it wrote proves nothing about what ended up in the schema.
     *
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        $rows = (array) $this->connection->query(sprintf('PRAGMA table_info(%s)', $table))->fetchAll();

        return array_map(static fn ($row): string => (string) ((array) $row)['name'], $rows);
    }

    /**
     * Index names defined on a table.
     *
     * @return list<string>
     */
    private function indexesOf(string $table): array
    {
        $rows = (array) $this->connection->query(sprintf('PRAGMA index_list(%s)', $table))->fetchAll();

        return array_map(static fn ($row): string => (string) ((array) $row)['name'], $rows);
    }
}