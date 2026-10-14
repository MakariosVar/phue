<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Schema;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Connection;
use Phue\Core\Database\Drivers\Abstract\PdoDriverInterface;
use Phue\Modules\DevTool\ProfilingConnection;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Schema\SchemaInspector;
use Phue\Modules\Schii\Schema\SchemaOperation;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Modules\Schii\Schema\SchemaSyncEngine;

#[UI(label: 'Profiled Articles')]
class ProfiledArticle extends BaseActiveRecord
{
    protected string $table = 'profiled_articles';

    #[Field(type: 'integer')]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', required: true)]
    #[Column(label: 'Title')]
    public string $title = '';
}

#[UI(label: 'Profiled Articles V2')]
class ProfiledArticleV2 extends BaseActiveRecord
{
    protected string $table = 'profiled_articles';

    #[Field(type: 'integer')]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', required: true)]
    #[Column(label: 'Title')]
    public string $title = '';

    #[Field(type: 'text')]
    #[Column(label: 'Slug')]
    public string $slug = '';
}

/**
 * The schema diff path with the debug toolbar switched on — the configuration a developer
 * actually runs in, and therefore the one the plain SchemaSyncEngineTest never reaches.
 *
 * Two modules meet here on purpose. DevTool replaces the bound Connection with a profiled
 * one; Schii then asks that connection for a raw PDO handle to introspect the live
 * database. Nothing in either module is wrong on its own, so neither module's own tests
 * fail: the contract that broke is the one between them, which is why the guard has to be
 * written across the seam.
 */
#[Group('integration')]
final class SchemaSyncEngineProfilingTest extends TestCase
{
    private string $database;
    private RequestProfiler $profiler;
    private Connection $connection;
    private SchemaSyncEngine $engine;
    private SchemaInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = sys_get_temp_dir() . '/phue_schii_profiled_' . uniqid() . '.sqlite';

        $this->profiler = new RequestProfiler(new Stopwatch());
        $this->profiler->start();

        $this->connection = new ProfilingConnection(
            ['driver' => 'sqlite', 'database' => $this->database],
            $this->profiler
        );

        $this->inspector = new SchemaInspector($this->connection);

        $this->engine = new SchemaSyncEngine(
            new SchemaRegistry(),
            $this->inspector,
            $this->connection
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        @unlink($this->database);
    }

    public function testDiffReadsTheLiveDatabaseThroughTheProfiledConnection(): void
    {
        $diff = $this->engine->diff(ProfiledArticle::class);

        $this->assertFalse($diff->isEmpty());
        $this->assertSame('profiled_articles', $diff->table);
    }

    public function testEngineExposesTheHandleItDiffedAgainst(): void
    {
        // The endpoint reads the dialect from this handle to render the plan, so a broken
        // handle is a 500 on the response even after the diff itself succeeded.
        $pdo = $this->engine->getPdo();

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertSame('sqlite', (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function testApplyWritesThroughTheProfiledConnection(): void
    {
        $sql = $this->engine->apply(ProfiledArticle::class, dryRun: false);

        $this->assertNotEmpty($sql);
        $this->assertTrue($this->inspector->tableExists('profiled_articles'));
    }

    public function testDiffIsEmptyImmediatelyAfterApply(): void
    {
        // The proof that both halves ran on one handle: apply() executes its DDL on the raw
        // PDO, and diff() then reads the schema through the same connection. Two handles
        // would leave the diff describing a database nobody wrote to.
        $this->engine->apply(ProfiledArticle::class, dryRun: false);

        $this->assertTrue($this->engine->diff(ProfiledArticle::class)->isEmpty());
    }

    public function testApplyThenDiffDetectsTheAddedColumn(): void
    {
        $this->engine->apply(ProfiledArticle::class, dryRun: false);

        $diff = $this->engine->diff(ProfiledArticleV2::class);

        $this->assertFalse($diff->isEmpty());

        $ops = array_map(static fn(array $op): SchemaOperation => $op['op'], $diff->operations);

        $this->assertContains(SchemaOperation::AddColumn, $ops);
    }

    public function testDryRunLeavesTheDatabaseUntouched(): void
    {
        $this->engine->apply(ProfiledArticle::class, dryRun: true);

        $this->assertFalse($this->inspector->tableExists('profiled_articles'));
    }

    public function testProfilingStillReportsQueriesOnTheProfiledConnection(): void
    {
        // Schema work is not a reason to lose the profiler: with the decorator in place,
        // ordinary statements must still be measured exactly as before.
        $this->engine->apply(ProfiledArticle::class, dryRun: false);
        $this->profiler->start();

        $this->connection->query('SELECT id FROM profiled_articles')->fetchAll();

        $this->assertContains('db.query', array_column($this->profiler->getSpans(), 'label'));
    }

    public function testProfiledConnectionStillAdvertisesTheDriverCapability(): void
    {
        $this->assertInstanceOf(PdoDriverInterface::class, $this->connection->getDriver());
    }
}
