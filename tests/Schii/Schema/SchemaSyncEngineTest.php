<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Schema;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Connection;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Schema\SchemaInspector;
use Phue\Modules\Schii\Schema\SchemaOperation;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Modules\Schii\Schema\SchemaSyncEngine;
use Phue\Tests\Support\UsesIsolatedDatabase;

/**
 * Syncing models on a real in-memory SQLite database: diff detection, dry-run
 * SQL emission and destructive apply semantics.
 *
 * @package Phue\Tests\Schii\Schema
 */
#[UI(label: 'Sync Widgets')]
class SyncWidget extends BaseActiveRecord
{
    protected string $table = 'sync_widgets';

    #[Field(type: 'integer')]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', required: true)]
    public string $name = '';
}

#[UI(label: 'Sync Widgets V2')]
class SyncWidgetV2 extends BaseActiveRecord
{
    protected string $table = 'sync_widgets';

    #[Field(type: 'integer')]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', required: true)]
    public string $name = '';

    #[Field(type: 'text')]
    public string $sku = '';
}

#[UI(label: 'Sync Widgets V3 (dropped name)')]
class SyncWidgetV3 extends BaseActiveRecord
{
    protected string $table = 'sync_widgets';

    #[Field(type: 'integer')]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text')]
    public string $sku = '';
}

#[UI(label: 'Sync Indexed')]
class SyncIndexed extends BaseActiveRecord
{
    protected string $table = 'sync_indexed';

    #[Field(type: 'integer')]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', required: true)]
    public string $sku = '';

    #[Field(type: 'text', indexed: true)]
    public string $email = '';
}

#[UI(label: 'Sync Indexed V2 (email un-indexed)')]
class SyncIndexedV2 extends BaseActiveRecord
{
    protected string $table = 'sync_indexed';

    #[Field(type: 'integer')]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', required: true)]
    public string $sku = '';

    #[Field(type: 'text', indexed: false)]
    public string $email = '';
}

#[UI(label: 'Sync Typed (json + boolean)')]
class SyncTyped extends BaseActiveRecord
{
    protected string $table = 'sync_typed';

    #[Field(type: 'integer')]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'json')]
    public array $meta = [];

    #[Field(type: 'boolean')]
    public bool $active = false;
}

#[\PHPUnit\Framework\Attributes\Group('integration')]
final class SchemaSyncEngineTest extends TestCase
{
    use UsesIsolatedDatabase;

    private Connection $db;
    private SchemaSyncEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = BaseActiveRecord::getConnection();

        // Drop tables for test isolation
        $this->db->execute('DROP TABLE IF EXISTS sync_widgets');
        $this->db->execute('DROP TABLE IF EXISTS sync_indexed');
        $this->db->execute('DROP TABLE IF EXISTS sync_typed');

        $registry = new SchemaRegistry();
        $this->engine = new SchemaSyncEngine(
            $registry,
            new SchemaInspector($this->db),
            $this->db,
        );
    }

    public function testDiffOnMissingTableEmitsCreateAndAddColumns(): void
    {
        $diff = $this->engine->diff(SyncWidget::class);

        $this->assertFalse($diff->isEmpty());

        $ops = array_map(static fn (array $op) => $op['op'], $diff->operations);
        $this->assertContains(SchemaOperation::CreateTable, $ops);
        $this->assertContains(SchemaOperation::AddColumn, $ops);
    }

    public function testDiffOnFreshlyAppliedSchemaIsEmpty(): void
    {
        $this->engine->apply(SyncWidget::class, dryRun: false);

        $diff = $this->engine->diff(SyncWidget::class);

        $this->assertTrue($diff->isEmpty());
        $this->assertSame([], $diff->operations);
    }

    public function testDiffAddsNewColumnAfterTableExists(): void
    {
        $this->engine->apply(SyncWidget::class, dryRun: false);

        $diff = $this->engine->diff(SyncWidgetV2::class);

        $ops = array_map(static fn (array $op) => $op['op'], $diff->operations);
        $this->assertContains(SchemaOperation::AddColumn, $ops);
        $this->assertNotContains(SchemaOperation::CreateTable, $ops);
    }

    public function testDiffDetectsDropForRemovedField(): void
    {
        $this->engine->apply(SyncWidgetV2::class, dryRun: false);

        $diff = $this->engine->diff(SyncWidgetV3::class);

        $this->assertTrue($diff->hasDrops());
    }

    public function testDryRunReturnsSqlWithoutApplying(): void
    {
        $sql = $this->engine->apply(SyncWidget::class, dryRun: true);

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('sync_widgets', implode(' ', $sql));

        // Table must NOT exist yet.
        $this->assertFalse((new SchemaInspector($this->db))->tableExists('sync_widgets'));
    }

    public function testRealApplyCreatesTable(): void
    {
        $sql = $this->engine->apply(SyncWidget::class, dryRun: false);

        $this->assertNotEmpty($sql);
        $this->assertTrue((new SchemaInspector($this->db))->tableExists('sync_widgets'));
    }

    public function testApplyForbidsDropsUnlessRequired(): void
    {
        $this->engine->apply(SyncWidgetV2::class, dryRun: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('allowDrops');

        $this->engine->apply(SyncWidgetV3::class, dryRun: false, allowDrops: false);
    }

    public function testApplyAllowsDropsWhenConfirmed(): void
    {
        $this->engine->apply(SyncWidgetV2::class, dryRun: false);

        $sql = $this->engine->apply(SyncWidgetV3::class, dryRun: false, allowDrops: true);

        $this->assertNotEmpty($sql);

        // 'name' column must be gone.
        $columns = (new SchemaInspector($this->db))->inspect('sync_widgets');
        $this->assertArrayNotHasKey('name', $columns);
        $this->assertArrayHasKey('sku', $columns);
    }

    public function testDiffThrowsForNonActiveRecord(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->engine->diff(\stdClass::class);
    }

    public function testCreateIndexScheduledForIndexedFieldOnFreshTable(): void
    {
        $diff = $this->engine->diff(SyncIndexed::class);

        $ops = array_map(static fn (array $op) => $op['op'], $diff->operations);
        $this->assertContains(SchemaOperation::CreateTable, $ops);
        $this->assertContains(SchemaOperation::CreateIndex, $ops);
        $this->assertNotContains(SchemaOperation::DropIndex, $ops);
    }

    public function testFreshApplyEmitsCreateIndexSql(): void
    {
        $sql = $this->engine->apply(SyncIndexed::class, dryRun: true);

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString(
            'CREATE INDEX IF NOT EXISTS "idx_sync_indexed_email" ON "sync_indexed" ("email")',
            implode(' ; ', $sql)
        );
    }

    public function testApplyCreatesRealIndexAndDiffGoesEmpty(): void
    {
        $this->engine->apply(SyncIndexed::class, dryRun: false);

        $inspector = new SchemaInspector($this->db);
        $columns = $inspector->inspect('sync_indexed');

        $this->assertTrue($columns['email']->indexed, 'email must be actually indexed in sqlite');
        $this->assertFalse($columns['id']->indexed ?? false, 'the PK column must not carry a separate user index');

        $this->assertTrue($this->engine->diff(SyncIndexed::class)->isEmpty());
    }

    public function testRemovingIndexFlagSchedulesDropIndex(): void
    {
        $this->engine->apply(SyncIndexed::class, dryRun: false);

        $diff = $this->engine->diff(SyncIndexedV2::class);

        $ops = array_map(static fn (array $op) => $op['op'], $diff->operations);
        $this->assertContains(SchemaOperation::DropIndex, $ops);
        $this->assertNotContains(SchemaOperation::CreateIndex, $ops);
    }

    public function testDropIndexIsApplied(): void
    {
        $this->engine->apply(SyncIndexed::class, dryRun: false);

        $this->engine->apply(SyncIndexedV2::class, dryRun: false);

        $columns = (new SchemaInspector($this->db))->inspect('sync_indexed');
        $this->assertFalse($columns['email']->indexed, 'email index must be dropped after sync');
        $this->assertTrue($this->engine->diff(SyncIndexedV2::class)->isEmpty());
    }

    public function testIndexOnlyChangeDoesNotTriggerColumnAlter(): void
    {
        $this->engine->apply(SyncIndexed::class, dryRun: false);

        $diff = $this->engine->diff(SyncIndexedV2::class);

        foreach ($diff->operations as $opData) {
            $this->assertNotSame(SchemaOperation::AlterColumn, $opData['op']);
        }
    }

    // ─────────────────────── type-compatibility round-trips ───────────────────────

    public function testJsonColumnRoundTripIsIdempotentOnSqlite(): void
    {
        $this->engine->apply(SyncTyped::class, dryRun: false);

        $diff = $this->engine->diff(SyncTyped::class);

        $this->assertTrue(
            $diff->isEmpty(),
            'A json column stored as TEXT on sqlite must not diff against the target json type.'
        );
    }

    public function testBooleanColumnRoundTripIsIdempotentOnSqlite(): void
    {
        $this->engine->apply(SyncTyped::class, dryRun: false);

        $columns = (new SchemaInspector($this->db))->inspect('sync_typed');
        $this->assertSame('int', $columns['active']->type, 'sqlite stores boolean as INTEGER, inspector reads int');

        $diff = $this->engine->diff(SyncTyped::class);

        $this->assertTrue(
            $diff->isEmpty(),
            'A boolean column stored as INTEGER on sqlite must be type-compatible with the target boolean type.'
        );
    }

    public function testPgsqlBoolAndIntAreNotTypeCompatible(): void
    {
        $method = new \ReflectionMethod(SchemaSyncEngine::class, 'areTypesCompatible');

        $engine = $method->invoke(
            $this->engine,
            'int',
            'boolean',
            'pgsql'
        );

        $this->assertFalse($engine, 'On pgsql BOOLEAN is a distinct physical type from INT');
    }

    public function testSqliteBoolAndIntAreTypeCompatible(): void
    {
        $method = new \ReflectionMethod(SchemaSyncEngine::class, 'areTypesCompatible');

        $compatible = $method->invoke($this->engine, 'int', 'boolean', 'sqlite');

        $this->assertTrue($compatible, 'On sqlite BOOLEAN is stored as INTEGER, so int and boolean are the same physical type');
    }
}