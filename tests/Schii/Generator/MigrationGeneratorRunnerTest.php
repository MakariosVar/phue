<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Generator;

use Phue\Core\Database\Connection;
use Phue\Core\Database\Migrator;
use Phue\Modules\Phii\Generator\MigrationGenerator;
use Phue\Modules\Schii\Generator\PhiiExportSchema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P10 — Phii-generated migrations must be (a) collision-free and (b) actually
 * runnable by the Migrator engine (naming contract + runnable class + real DDL).
 * Today: same-second generations share a filename and the emitted file cannot be
 * loaded by Migrator at all.
 *
 * @package Phue\Tests\Schii\Generator
 */
#[Group('unit')]
final class MigrationGeneratorRunnerTest extends TestCase
{
    private string $dump;
    private string $migrationsDir;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dump = sys_get_temp_dir() . '/phue_migration_run_' . uniqid();
        $this->migrationsDir = $this->dump . '/migrations';
        @mkdir($this->migrationsDir, 0755, true);
        $this->db = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dump)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dump, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink()
                    ? rmdir($item->getPathname())
                    : unlink($item->getPathname());
            }
            rmdir($this->dump);
        }
        parent::tearDown();
    }

    private function schema(string $table = 'probes'): PhiiExportSchema
    {
        return new PhiiExportSchema(
            entity: 'Probe',
            className: 'App\\Models\\Probe',
            label: 'Probe',
            icon: 'probe',
            table: $table,
            module: 'admin',
            namespace: 'App\\Models',
            fields: [],
            permissions: [],
        );
    }

    public function testSameSecondGenerationYieldsDistinctFilenames(): void
    {
        $generator = new MigrationGenerator();

        $first = $generator->generateFromSchema($this->schema(), $this->migrationsDir, 'sqlite');
        $second = $generator->generateFromSchema($this->schema(), $this->migrationsDir, 'sqlite');

        $paths = array_merge(
            array_map(static fn ($m) => $m->path, $first),
            array_map(static fn ($m) => $m->path, $second)
        );

        $this->assertCount(
            count($paths),
            array_unique($paths),
            'Two migrations generated for the same table must never collide; ' .
            'a shared timestamp means silent overwrite and data loss.'
        );
    }

    public function testGeneratedMigrationRunsThroughMigrator(): void
    {
        $generator = new MigrationGenerator();
        $mutations = $generator->generateFromSchema($this->schema(), $this->migrationsDir, 'sqlite');

        foreach ($mutations as $mutation) {
            @mkdir(dirname($mutation->path), 0755, true);
            $mutation->write();
        }

        $migrator = new Migrator($this->db, $this->migrationsDir);

        try {
            $applied = $migrator->run();
        } catch (\Throwable $e) {
            $this->fail('Generated migration could not be executed: ' . $e->getMessage());
        }

        $this->assertNotEmpty($applied, 'The generated migration must be discovered and applied.');
        $this->assertTrue($this->tableExists('probes'), 'up() must actually create the table on the connection.');
    }

    private function tableExists(string $table): bool
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS c FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );
        $rows = is_object($result) && method_exists($result, 'fetchAll') ? $result->fetchAll() : (array) $result;
        $count = (int) ($rows[0]['c'] ?? (is_object($rows[0] ?? null) ? $rows[0]->c : 0));
        return $count > 0;
    }
}