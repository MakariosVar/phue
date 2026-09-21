<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Endpoint;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Modules\Schii\Endpoint\SchemaInspectorEndpoint;
use Phue\Modules\Schii\Endpoint\SchemaSyncEndpoint;
use Phue\Modules\Schii\Schema\SchemaInspector;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Modules\Schii\Schema\SchemaSyncEngine;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Explorer endpoints (structure + sync):
 * real database introspection, honest JSON payloads and destructive
 * operations gated behind allowDrops.
 *
 * @package Phue\Tests\Schii\Endpoint
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class SchemaExplorerEndpointsTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        BaseActiveRecord::setConnection($this->db);

        $this->db->execute("
            CREATE TABLE profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name TEXT NOT NULL,
                email TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 0,
                legacy TEXT NULL
            );
        ");
    }

    private function inspectorEndpoint(): SchemaInspectorEndpoint
    {
        return new SchemaInspectorEndpoint(
            new SchemaRegistry(),
            new SchemaInspector($this->db),
            $this->db,
            'Phue\\Tests\\Schii\\Stubs'
        );
    }

    private function syncEndpoint(): SchemaSyncEndpoint
    {
        return new SchemaSyncEndpoint(
            new SchemaSyncEngine(
                new SchemaRegistry(),
                new SchemaInspector($this->db),
                $this->db
            ),
            'Phue\\Tests\\Schii\\Stubs'
        );
    }

    private function columnNames(string $table): array
    {
        $stmt = $this->db->query("PRAGMA table_info({$table})");
        $rows = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function testStructureEndpointReportsDbColumnsWithTypes(): void
    {
        $request = new Request('GET', '/api/schii/schema/profiles/structure');
        $response = $this->inspectorEndpoint()->structure($request, ['entity' => 'profiles']);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertSame('profiles', $data['table']);
        $this->assertArrayHasKey('id', $data['columns']);
        $this->assertArrayHasKey('full_name', $data['columns']);
        $this->assertArrayHasKey('email', $data['columns']);
        $this->assertSame('id', $data['columns']['id']['name']);
        $this->assertSame('int', $data['columns']['id']['type']);
        $this->assertTrue($data['columns']['id']['primaryKey']);
    }

    public function testStructureEndpoint404ForUnknownEntity(): void
    {
        $request = new Request('GET', '/api/schii/schema/bogus/structure');
        $response = $this->inspectorEndpoint()->structure($request, ['entity' => 'bogus']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Resource not found', json_decode($response->getContent(), true)['error']);
    }

    public function testSyncEndpointDryRunPlansDropWithoutTouchingDb(): void
    {
        $request = new Request('POST', '/api/schii/schema/profiles/sync', [], []);
        $response = $this->syncEndpoint()->sync($request, ['entity' => 'profiles']);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertSame('profiles', $data['table']);
        $ops = array_column($data['ops'] ?? $data['operations'] ?? [], 'op');
        $this->assertContains('drop-column', $ops, 'legacy column must be planned for removal');

        // Dry run must never touch the database
        $this->assertContains('legacy', $this->columnNames('profiles'));
    }

    public function testSyncEndpointApplyRefusesDropsWithoutExplicitConsent(): void
    {
        $request = new Request('POST', '/api/schii/schema/profiles/sync', [], [
            'apply' => true,
            'allowDrops' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/allowDrops/');

        $this->syncEndpoint()->sync($request, ['entity' => 'profiles']);
    }

    public function testSyncEndpointApplyWithAllowDropsRebuildsSqliteTable(): void
    {
        $request = new Request('POST', '/api/schii/schema/profiles/sync', [], [
            'apply' => true,
            'allowDrops' => true,
        ]);

        $response = $this->syncEndpoint()->sync($request, ['entity' => 'profiles']);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertCount(4, $data['executed'], 'SQLite rebuild = create, copy, drop, rename');

        $this->assertStringContainsString('CREATE TABLE ', $data['executed'][0]);
        $this->assertNotContains('legacy', $this->columnNames('profiles'));
        $this->assertContains('id', $this->columnNames('profiles'));
        $this->assertContains('email', $this->columnNames('profiles'));
    }

    public function testSyncEndpointApplyCreatesMissingTable(): void
    {
        $this->db->execute('DROP TABLE profiles');

        $request = new Request('POST', '/api/schii/schema/profiles/sync', [], [
            'apply' => true,
            'allowDrops' => true,
        ]);

        $response = $this->syncEndpoint()->sync($request, ['entity' => 'profiles']);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['executed']);
        $this->assertContains('id', $this->columnNames('profiles'));
        $this->assertContains('full_name', $this->columnNames('profiles'));
        $this->assertContains('active', $this->columnNames('profiles'));
        $this->assertNotContains('legacy', $this->columnNames('profiles'));
    }
}