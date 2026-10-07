<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Schema;

use Phue\Core\Database\Connection;
use Phue\Core\Database\SqlIdentifier;
use Phue\Modules\Queue\Exception\SchemaException;
use Phue\Modules\Queue\QueueConfig;

/**
 * The queue's own tables, created and upgraded by the module that needs them.
 *
 * Deliberately not a file in the application's migration directory, for one reason that has
 * nothing to do with preference: application migrations roll back in batches, and a queue table
 * that rolls back is a worker holding a job against a table that no longer has a row for it. The
 * failure is not "the queue is empty", it is work silently lost, mid-deploy, in the one component
 * that nobody is watching at that moment.
 *
 * A framework table is a different kind of thing from a project's history. It ships with the code
 * that understands it, it is upgraded by a versioned step rather than by undoing a step, and its
 * lifecycle is the module's own.
 *
 * Every method is idempotent and every statement is written out rather than generated, because
 * this runs on a live database in front of jobs that are already stored in it.
 *
 * @package Phue\Modules\Queue\Schema
 */
final class QueueSchema
{
    /** Current schema version. Bumped when an upgrade step is added. */
    public const VERSION = 1;

    /** Suffix for the dead-letter table, derived from the live table's name. */
    public const FAILED_SUFFIX = '_failed';

    /** Suffix for the small key/value table that records the installed version. */
    public const META_SUFFIX = '_meta';

    /**
     * @param Connection $connection Where the tables live.
     * @param string     $table     Name of the live jobs table; the dead-letter and version tables
     *                              are derived from it.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {
    }

    /**
     * The table live jobs live in.
     */
    public function jobsTable(): string
    {
        return $this->table;
    }

    /**
     * The table dead jobs are recorded in, so the live one never accumulates failures.
     */
    public function failedTable(): string
    {
        return $this->table . self::FAILED_SUFFIX;
    }

    /**
     * The table recording which schema version is installed.
     */
    public function metaTable(): string
    {
        return $this->table . self::META_SUFFIX;
    }

    /**
     * Create anything missing and bring an older schema up to date.
     *
     * Safe to run against a database that already has jobs in it.
     *
     * @return list<string> The steps that were applied, in order. Empty means already current.
     *
     * @throws SchemaException When a statement fails, with the driver's own message attached.
     */
    public function install(): array
    {
        $applied = [];
        $version = $this->installedVersion();

        if ($version === null) {
            $this->createJobs();
            $this->createFailed();
            $this->createMeta();
            $this->writeVersion(self::VERSION);
            $applied[] = 'created';

            return $applied;
        }

        if ($version < self::VERSION) {
            // Upgrade steps go here, each one idempotent, guarded by the version it moves from.
            // Deliberately a chain rather than a loop: each step is different SQL, and pretending
            // otherwise is how a migration ends up unmaintainable.
            $this->writeVersion(self::VERSION);
            $applied[] = 'upgraded from ' . $version;
        }

        return $applied;
    }

    /**
     * Remove every table this module owns.
     *
     * Ordered so that a failure part-way leaves the live table intact rather than taking out the
     * dead-letter record that explains what went wrong.
     *
     * @return list<string> The tables that were dropped.
     */
    public function drop(): array
    {
        $dropped = [];
        $driver = $this->driverName();

        foreach ([$this->failedTable(), $this->metaTable(), $this->jobsTable()] as $table) {
            $this->execute(sprintf('DROP TABLE IF EXISTS %s', SqlIdentifier::quote($table, $driver)));
            $dropped[] = $table;
        }

        return $dropped;
    }

    /**
     * The installed schema version, or null when the module has never been installed.
     */
    public function installedVersion(): ?int
    {
        if (!$this->hasTable($this->metaTable())) {
            return null;
        }

        try {
            $rows = $this->select(
                sprintf(
                    'SELECT %s FROM %s WHERE %s = ? LIMIT 1',
                    SqlIdentifier::quote('value', $this->driverName()),
                    SqlIdentifier::quote($this->metaTable(), $this->driverName()),
                    SqlIdentifier::quote('name', $this->driverName())
                ),
                ['schema_version']
            );
        } catch (SchemaException) {
            return null;
        }

        if ($rows === []) {
            return null;
        }

        $row = (array) $rows[0];

        return isset($row['value']) ? (int) $row['value'] : null;
    }

    /**
     * Whether the table live jobs are stored in exists.
     */
    public function hasTable(string $table): bool
    {
        return match ($this->driverName()) {
            'sqlite' => $this->selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
                [$table]
            ) !== null,
            'mysql' => $this->informationSchemaHasTable($table),
            default => $this->selectOne(
                'SELECT tablename FROM pg_catalog.pg_tables WHERE tablename = ? LIMIT 1',
                [$table]
            ) !== null,
        };
    }

    /**
     * @return array<int, string>
     */
    public function tables(): array
    {
        return [$this->jobsTable(), $this->failedTable(), $this->metaTable()];
    }

    // ------------------------------------------------------------------ DDL

    /**
     * The live jobs table.
     *
     * The claim query filters on status, queue and available_at in that order and sorts by
     * available_at then id, so the composite index is shaped for exactly that. The second index
     * exists for the reaper, which scans by reservation expiry alone — a column the first index
     * does not contain.
     *
     * Attempts is incremented inside the claim statement rather than by the worker, so a job
     * cannot be handed out twice and counted once.
     */
    private function createJobs(): void
    {
        $driver = $this->driverName();
        $id = $this->autoIncrementPrimaryKey($driver);
        $table = SqlIdentifier::quote($this->table, $driver);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS {$table} (
                {$id},
                `queue` VARCHAR(64) NOT NULL,
                `job_class` VARCHAR(255) NOT NULL,
                `payload` TEXT NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                `attempts` INT NOT NULL DEFAULT 0,
                `available_at` BIGINT NOT NULL,
                `reserved_at` BIGINT NULL,
                `reserved_until` BIGINT NULL,
                `reserved_by` VARCHAR(128) NULL,
                `completed_at` BIGINT NULL,
                `created_at` BIGINT NOT NULL
            )
        SQL);

        // Timestamps are BIGINT rather than DATETIME for one reason: they are only ever compared
        // for order and for "is this in the past", and an integer cannot be read in the server's
        // timezone or written in the wrong format. MySQL DATETIME silently converts, SQLite
        // stores whatever string it is given, and the three disagree.
        $this->execute(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s (`status`, `queue`, `available_at`, `id`)',
            $this->indexName('claim'),
            $table
        ));

        $this->execute(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s (`status`, `reserved_until`)',
            $this->indexName('lease'),
            $table
        ));
    }

    /**
     * The dead-letter table.
     *
     * Separate from the live table on purpose. Failures accumulate forever, the live table is read
     * on every claim, and their retention policies have nothing in common — so keeping them
     * together would mean the hot path pays for the failures and the failures can never be
     * dropped without dropping the hot path.
     *
     * The full payload is kept so a job can be replayed, which is the only reason the record is
     * worth having.
     */
    private function createFailed(): void
    {
        $driver = $this->driverName();
        $id = $this->autoIncrementPrimaryKey($driver);
        $table = SqlIdentifier::quote($this->failedTable(), $driver);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS {$table} (
                {$id},
                `queue` VARCHAR(64) NOT NULL,
                `job_class` VARCHAR(255) NOT NULL,
                `payload` TEXT NOT NULL,
                `attempts` INT NOT NULL DEFAULT 0,
                `exception` TEXT NOT NULL,
                `failed_at` BIGINT NOT NULL
            )
        SQL);

        $this->execute(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s (`failed_at`)',
            $this->indexName('failed_at'),
            $table
        ));

        $this->execute(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s (`queue`, `job_class`)',
            $this->indexName('failed_queue'),
            $table
        ));
    }

    /**
     * The schema version record.
     */
    private function createMeta(): void
    {
        $driver = $this->driverName();
        $table = SqlIdentifier::quote($this->metaTable(), $driver);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS {$table} (
                `name` VARCHAR(64) NOT NULL PRIMARY KEY,
                `value` VARCHAR(255) NOT NULL
            )
        SQL);
    }

    /**
     * Record the schema version, overwriting whatever was there.
     *
     * Written as an UPDATE followed by a conditional INSERT rather than an upsert, because the
     * upsert spellings differ across MySQL, PostgreSQL and SQLite, and a queue that cannot be
     * installed is a queue nobody can run.
     *
     * @param int $version Version to record.
     */
    private function writeVersion(int $version): void
    {
        $driver = $this->driverName();
        $table = SqlIdentifier::quote($this->metaTable(), $driver);
        $name = SqlIdentifier::quote('name', $driver);
        $value = SqlIdentifier::quote('value', $driver);

        // The portable spelling of an upsert. Every driver supports it and it is idempotent, which
        // matters because install() has to be safe to re-run against a live queue.
        $this->execute(sprintf(
            'UPDATE %s SET %s = ? WHERE %s = ?',
            $table,
            $value,
            $name
        ), [(string) $version, 'schema_version']);

        $this->execute(sprintf(
            'INSERT INTO %s (%s, %s) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM %s WHERE %s = ?)',
            $table,
            $name,
            $value,
            $table,
            $name
        ), ['schema_version', (string) $version, 'schema_version']);
    }

    /**
     * The primary key spelling each driver wants.
     *
     * Three different ways to say "an integer that counts up", none of which is portable.
     */
    private function autoIncrementPrimaryKey(string $driver): string
    {
        return match ($driver) {
            'mysql' => '`id` INT AUTO_INCREMENT PRIMARY KEY',
            'pgsql', 'postgres', 'postgresql' => '"id" SERIAL PRIMARY KEY',
            default => '`id` INTEGER PRIMARY KEY AUTOINCREMENT',
        };
    }

    /**
     * A deterministic index name, since three of them exist and they must not collide when the
     * table has been renamed.
     */
    private function indexName(string $suffix): string
    {
        return $this->table . '_' . $suffix . '_idx';
    }

    /**
     * Whether a table exists, asked the way MySQL answers it.
     *
     * @param string $table Table to look for.
     */
    private function informationSchemaHasTable(string $table): bool
    {
        return $this->selectOne(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            [$table]
        ) !== null;
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * The driver in use, lower-cased so the driver switches below can be exhaustive.
     */
    private function driverName(): string
    {
        return strtolower($this->connection->getDriver()->getName());
    }

    /**
     * @param array<int|string, mixed> $params Bindings for the statement.
     */
    private function execute(string $sql, array $params = []): void
    {
        try {
            $this->connection->execute($sql, $params);
        } catch (\Throwable $e) {
            throw new SchemaException(
                sprintf('Queue schema statement failed on driver "%s": %s', $this->driverName(), $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * @param array<int|string, mixed> $params Bindings for the statement.
     *
     * @return list<array<string, mixed>>
     */
    private function select(string $sql, array $params = []): array
    {
        try {
            $statement = $this->connection->query($sql, $params);
        } catch (\Throwable $e) {
            throw new SchemaException(
                sprintf('Queue schema query failed on driver "%s": %s', $this->driverName(), $e->getMessage()),
                0,
                $e
            );
        }

        $rows = is_object($statement) && method_exists($statement, 'fetchAll')
            ? $statement->fetchAll()
            : (array) $statement;

        return array_map(static fn ($row): array => (array) $row, is_array($rows) ? $rows : [$rows]);
    }

    /**
     * @param array<int|string, mixed> $params Bindings for the statement.
     *
     * @return array<string, mixed>|null Null when there is no such row.
     */
    private function selectOne(string $sql, array $params = []): ?array
    {
        return $this->select($sql, $params)[0] ?? null;
    }
}