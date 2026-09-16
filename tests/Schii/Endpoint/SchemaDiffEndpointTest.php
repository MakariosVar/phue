<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Endpoint;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Modules\Schii\Endpoint\SchemaSyncEndpoint;
use Phue\Modules\Schii\Schema\SchemaInspector;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Modules\Schii\Schema\SchemaSyncEngine;
use PHPUnit\Framework\TestCase;

/**
 * SchemaSyncEndpoint::diff contract consumed by the Schii Diff panel:
 * { table, ops[{op,column?,new?}], destructive, sqls, hasChanges } over a real sqlite store.
 *
 * @package Phue\Tests\Schii\Endpoint
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class SchemaDiffEndpointTest extends TestCase
{
    private Connection $db;
    private SchemaSyncEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        BaseActiveRecord::setConnection($this->db);

        // Model knows id/full_name/email/active; DB additionally holds a leftover legacy column.
        $this->db->execute("CREATE TABLE profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 0,
            legacy TEXT NULL
        )");

        $this->endpoint = new SchemaSyncEndpoint(
            new SchemaSyncEngine(
                new SchemaRegistry(),
                new SchemaInspector($this->db),
                $this->db
            ),
            'Phue\\Tests\\Schii\\Stubs'
        );
    }

    private function decode(\Phue\Core\Response $response): array
    {
        return (array) json_decode($response->getContent(), true);
    }

    public function testDiffPlansDropOfLegacyColumn(): void
    {
        $request = new Request('GET', '/api/schii/schema/profiles/diff');
        $response = $this->endpoint->diff($request, ['entity' => 'profiles']);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);

        $this->assertSame('profiles', $data['table']);
        $this->assertTrue($data['hasChanges']);
        $this->assertTrue($data['destructive'], 'Dropping legacy must be flagged destructive.');

        $ops = array_column($data['ops'], 'op');
        $this->assertContains('drop-column', $ops);

        $drop = null;
        foreach ($data['ops'] as $op) {
            if ($op['op'] === 'drop-column') {
                $drop = $op;
            }
        }
        $this->assertNotNull($drop);
        $this->assertSame('legacy', $drop['column']['name']);
        $this->assertStringContainsStringIgnoringCase('legacy', $drop['column']['name']);
    }

    public function testDiffSqlsProduceSqliteRebuildPlan(): void
    {
        $request = new Request('GET', '/api/schii/schema/profiles/diff');
        $data = $this->decode($this->endpoint->diff($request, ['entity' => 'profiles']));

        $this->assertIsArray($data['sqls']);
        $this->assertNotEmpty($data['sqls']);
        $this->assertStringContainsString('CREATE TABLE ', $data['sqls'][0]);
        $this->assertStringContainsString('ALTER TABLE', $data['sqls'][3]);
    }

    public function testDiffReportsAddColumnForMissingModelColumn(): void
    {
        // DB already dropped the column the model still declares.
        $this->db->execute('DROP TABLE profiles');
        $this->db->execute("CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL, email TEXT NOT NULL)");

        $request = new Request('GET', '/api/schii/schema/profiles/diff');
        $data = $this->decode($this->endpoint->diff($request, ['entity' => 'profiles']));

        $ops = array_column($data['ops'], 'op');
        $this->assertContains('add-column', $ops);
    }

    public function testDiffIsSideEffectFree(): void
    {
        $request = new Request('GET', '/api/schii/schema/profiles/diff');
        $this->endpoint->diff($request, ['entity' => 'profiles']);

        // diff must never mutate the table
        $stmt = $this->db->query('PRAGMA table_info(profiles)');
        $rows = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;
        $names = array_column($rows, 'name');
        $this->assertContains('legacy', $names, 'diff must not drop columns.');
    }

    public function testDiffUnknownEntityReturns404(): void
    {
        $request = new Request('GET', '/api/schii/schema/nope/diff');
        $response = $this->endpoint->diff($request, ['entity' => 'nope']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Resource not found', $this->decode($response)['error']);
    }
}