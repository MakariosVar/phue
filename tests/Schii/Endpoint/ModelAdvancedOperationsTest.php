<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Endpoint;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Schii\Stubs\User;
use PHPUnit\Framework\TestCase;

/**
 * Advanced ModelEndpoint operations invoked by the Schii UI panels:
 * bulk update/delete, CSV/JSON export and JSON/CSV import. Exercises the
 * real sqlite store + the Profile stub end to end.
 *
 * @package Phue\Tests\Schii\Endpoint
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class ModelAdvancedOperationsTest extends TestCase
{
    private Connection $db;
    private ModelEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        BaseActiveRecord::setConnection($this->db);

        $this->db->execute("CREATE TABLE profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 0
        )");
        $this->db->execute("INSERT INTO profiles (full_name, email, active) VALUES ('Alice', 'alice@example.com', 1)");
        $this->db->execute("INSERT INTO profiles (full_name, email, active) VALUES ('Bob', 'bob@example.com', 1)");
        $this->db->execute("INSERT INTO profiles (full_name, email, active) VALUES ('Carol', 'carol@example.com', 0)");

        $this->endpoint = new ModelEndpoint(
            new SchemaRegistry(),
            entityNamespace: 'Phue\\Tests\\Schii\\Stubs'
        );
    }

    private function decode(\Phue\Core\Response $response): array
    {
        return (array) json_decode($response->getContent(), true);
    }

    // ─────────────────────────────── Bulk ───────────────────────────────

    public function testBulkDeleteRemovesSelectedRecords(): void
    {
        $request = new Request('POST', '/api/schii/profiles/bulk', [], [
            'action' => 'delete',
            'ids' => [1, 3],
        ]);

        $response = $this->endpoint->bulk($request, ['entity' => 'profiles']);
        $this->assertSame(200, $response->getStatusCode());

        $data = $this->decode($response);
        $this->assertSame(2, $data['summary']['deleted']);
        $this->assertSame(0, $data['summary']['notFound']);
        $this->assertSame([1, 3], array_column($data['results'], 'id'));

        $stmt = $this->db->query('SELECT COUNT(*) AS c FROM profiles');
        $rows = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;
        $this->assertSame(1, (int) array_values($rows[0])[0]);
    }

    public function testBulkDeleteReportsNotFoundIdsHonestly(): void
    {
        $request = new Request('POST', '/api/schii/profiles/bulk', [], [
            'action' => 'delete',
            'ids' => [1, 999],
        ]);

        $data = $this->decode($this->endpoint->bulk($request, ['entity' => 'profiles']));

        $this->assertSame(1, $data['summary']['deleted']);
        $this->assertSame(1, $data['summary']['notFound']);
        $this->assertSame('deleted', $data['results'][0]['status']);
        $this->assertSame('not_found', $data['results'][1]['status']);
    }

    public function testBulkUpdateModifiesAllSelectedRecords(): void
    {
        $request = new Request('POST', '/api/schii/profiles/bulk', [], [
            'action' => 'update',
            'ids' => [1, 2],
            'data' => ['active' => false],
        ]);

        $data = $this->decode($this->endpoint->bulk($request, ['entity' => 'profiles']));

        $this->assertSame(2, $data['summary']['updated']);
        $this->assertSame(0, $data['summary']['failed']);
        $this->assertSame('updated', $data['results'][0]['status']);
    }

    public function testBulkUpdateWithUnknownFieldFailsPerRecord(): void
    {
        $request = new Request('POST', '/api/schii/profiles/bulk', [], [
            'action' => 'update',
            'ids' => [1, 2],
            'data' => ['hacked' => 'yes'],
        ]);

        $data = $this->decode($this->endpoint->bulk($request, ['entity' => 'profiles']));

        $this->assertSame(0, $data['summary']['updated']);
        $this->assertSame(2, $data['summary']['failed']);
        $this->assertSame('failed', $data['results'][0]['status']);
        $this->assertArrayHasKey('hacked', $data['results'][0]['errors']);
    }

    public function testBulkRejectsInvalidActionWith422(): void
    {
        $request = new Request('POST', '/api/schii/profiles/bulk', [], [
            'action' => 'drop-table',
            'ids' => [1],
        ]);

        $response = $this->endpoint->bulk($request, ['entity' => 'profiles']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Invalid bulk action or empty ids', $this->decode($response)['error']);
    }

    public function testBulkRejectsEmptyIdsWith422(): void
    {
        $request = new Request('POST', '/api/schii/profiles/bulk', [], [
            'action' => 'delete',
            'ids' => [],
        ]);

        $response = $this->endpoint->bulk($request, ['entity' => 'profiles']);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testBulkUnknownEntityReturns404(): void
    {
        $request = new Request('POST', '/api/schii/nope/bulk', [], ['action' => 'delete', 'ids' => [1]]);
        $this->assertSame(404, $this->endpoint->bulk($request, ['entity' => 'nope'])->getStatusCode());
    }

    // ─────────────────────────────── Export ───────────────────────────────

    public function testExportJsonReturnsRows(): void
    {
        $request = new Request('GET', '/api/schii/profiles/export', ['format' => 'json']);
        $response = $this->endpoint->export($request, ['entity' => 'profiles']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->getHeader('Content-Type'));

        $rows = json_decode($response->getContent(), true);
        $this->assertCount(3, $rows);
        $this->assertSame('Alice', $rows[0]['full_name']);
        $this->assertSame('bob@example.com', $rows[1]['email']);
    }

    public function testExportCsvProducesStreamingAttachment(): void
    {
        $request = new Request('GET', '/api/schii/profiles/export', ['format' => 'csv']);
        $response = $this->endpoint->export($request, ['entity' => 'profiles']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv', $response->getHeader('Content-Type'));
        $this->assertSame('attachment; filename="profiles.csv"', $response->getHeader('Content-Disposition'));

        $csv = $response->getContent();
        $lines = array_filter(explode(PHP_EOL, $csv));
        $this->assertStringContainsString('full_name', (string) reset($lines));
        $this->assertStringContainsString('Alice', $csv);
        $this->assertStringContainsString('Carol', $csv);
    }

    public function testExportDefaultsToCsv(): void
    {
        $request = new Request('GET', '/api/schii/profiles/export', []);
        $response = $this->endpoint->export($request, ['entity' => 'profiles']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv', $response->getHeader('Content-Type'));
    }

public function testExportRejectsUnknownEntityReturns404(): void
    {
        $request = new Request('GET', '/api/schii/nope/export', []);
        $this->assertSame(404, $this->endpoint->export($request, ['entity' => 'nope'])->getStatusCode());
    }

    // ─────────────────────── A4: hidden/readonly fields & parity ───────────────────────

    private function userEndpoint(): ModelEndpoint
    {
        $this->db->execute('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            password_hash TEXT,
            auth_key TEXT,
            roles TEXT DEFAULT \'[]\'
        )');
        $this->db->execute(
            "INSERT INTO users (username, password_hash, auth_key, roles) VALUES ('alice', 'hash-a', 'ak-a', '[]')"
        );

        return new ModelEndpoint(
            new SchemaRegistry(),
            entityNamespace: 'Phue\\Tests\\Schii\\Stubs'
        );
    }

    public function testExportOmitsHiddenColumns(): void
    {
        $endpoint = $this->userEndpoint();

        $request = new Request('GET', '/api/schii/users/export', ['format' => 'json']);
        $rows = json_decode($endpoint->export($request, ['entity' => 'users'])->getContent(), true);

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('username', $rows[0]);
        $this->assertArrayNotHasKey('auth_key', $rows[0], 'column:false fields must never reach export output');
    }

    public function testExportIncludesReadOnlyButColumnVisibleFields(): void
    {
        $endpoint = $this->userEndpoint();

        $request = new Request('GET', '/api/schii/users/export', ['format' => 'json']);
        $rows = json_decode($endpoint->export($request, ['entity' => 'users'])->getContent(), true);

        $this->assertArrayHasKey('password_hash', $rows[0], 'column:true + writable:false field must still be exported');
        $this->assertSame('hash-a', $rows[0]['password_hash']);
    }

    public function testStoreSilentlyDropsNonWritableFields(): void
    {
        $endpoint = $this->userEndpoint();

        $request = new Request('POST', '/api/schii/users', [], [
            'username' => 'bob',
            'password_hash' => 'attacker-hash',
            'roles' => '["user"]',
        ]);

        $response = $endpoint->store($request, ['entity' => 'users']);
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('bob', $data['data']['username']);
        $this->assertNotSame('attacker-hash', $data['data']['password_hash'] ?? null);

        $row = $this->db->query("SELECT password_hash FROM users WHERE username = 'bob'");
        $stored = is_object($row) && method_exists($row, 'fetch') ? $row->fetch() : (array) $row;
        $this->assertNotSame('attacker-hash', array_values($stored)[0] ?? null, 'password_hash must not be persisted from payload');
    }

    public function testUpdateSilentlyDropsNonWritableFields(): void
    {
        $endpoint = $this->userEndpoint();

        $request = new Request('PUT', '/api/schii/users/1', [], [
            'username' => 'alice2',
            'password_hash' => 'hash-replacement',
        ]);

        $response = $endpoint->update($request, ['entity' => 'users', 'id' => '1']);
        $this->assertSame(200, $response->getStatusCode());

        $row = $this->db->query("SELECT password_hash FROM users WHERE id = 1");
        $stored = is_object($row) && method_exists($row, 'fetch') ? $row->fetch() : (array) $row;
        $this->assertSame('hash-a', array_values($stored)[0], 'password_hash must remain unchanged on update');
    }

    public function testBulkUpdateDropsNonWritableFields(): void
    {
        $endpoint = $this->userEndpoint();

        $request = new Request('POST', '/api/schii/users/bulk', [], [
            'action' => 'update',
            'ids' => [1],
            'data' => ['username' => 'alice9', 'password_hash' => 'bulk-hash'],
        ]);

        $data = json_decode($endpoint->bulk($request, ['entity' => 'users'])->getContent(), true);
        $this->assertSame(1, $data['summary']['updated']);

        $row = $this->db->query("SELECT password_hash FROM users WHERE id = 1");
        $stored = is_object($row) && method_exists($row, 'fetch') ? $row->fetch() : (array) $row;
        $this->assertSame('hash-a', array_values($stored)[0], 'bulk update must not persist non-writable password_hash');
    }

    public function testImportDropsNonWritableFields(): void
    {
        $endpoint = $this->userEndpoint();

        $request = new Request('POST', '/api/schii/users/import', [], [
            'format' => 'json',
            'data' => [
                ['username' => 'carol', 'password_hash' => 'import-hash'],
            ],
        ]);

        $data = json_decode($endpoint->import($request, ['entity' => 'users'])->getContent(), true);
        $this->assertSame(1, $data['imported']);

        $row = $this->db->query("SELECT password_hash FROM users WHERE username = 'carol'");
        $stored = is_object($row) && method_exists($row, 'fetch') ? $row->fetch() : (array) $row;
        $this->assertNotSame('import-hash', array_values($stored)[0] ?? null, 'import must not persist non-writable password_hash');
    }

    public function testServerMinMaxValidation(): void
    {
        $endpoint = $this->userEndpoint();

        $tooShort = new Request('POST', '/api/schii/users', [], ['username' => 'ab']);
        $response = $endpoint->store($tooShort, ['entity' => 'users']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('username', json_decode($response->getContent(), true)['errors']);

        $tooLong = new Request('POST', '/api/schii/users', [], ['username' => str_repeat('x', 41)]);
        $response = $endpoint->store($tooLong, ['entity' => 'users']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('username', json_decode($response->getContent(), true)['errors']);

        $ok = new Request('POST', '/api/schii/users', [], ['username' => 'validname']);
        $this->assertSame(201, $endpoint->store($ok, ['entity' => 'users'])->getStatusCode());
    }

    // ─────────────────────────────── Import ───────────────────────────────

    public function testImportInsertsNewRows(): void
    {
        $request = new Request('POST', '/api/schii/profiles/import', [], [
            'format' => 'json',
            'data' => [
                ['full_name' => 'Dave', 'email' => 'dave@example.com', 'active' => true],
                ['full_name' => 'Eve', 'email' => 'eve@example.com', 'active' => false],
            ],
        ]);

        $response = $this->endpoint->import($request, ['entity' => 'profiles']);
        $this->assertSame(200, $response->getStatusCode());

        $data = $this->decode($response);
        $this->assertSame(2, $data['imported']);
        $this->assertSame(0, $data['updated']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame([], $data['errors']);

        $stmt = $this->db->query('SELECT COUNT(*) AS c FROM profiles');
        $rows = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;
        $this->assertSame(5, (int) array_values($rows[0])[0]);
    }

    public function testImportMatchesExistingRowsByEmail(): void
    {
        $request = new Request('POST', '/api/schii/profiles/import', [], [
            'format' => 'json',
            'matchBy' => 'email',
            'data' => [
                ['full_name' => 'Alice Updated', 'email' => 'alice@example.com', 'active' => false],
            ],
        ]);

        $data = $this->decode($this->endpoint->import($request, ['entity' => 'profiles']));

        $this->assertSame(0, $data['imported']);
        $this->assertSame(1, $data['updated']);

        $stmt = $this->db->query("SELECT full_name FROM profiles WHERE email = 'alice@example.com'");
        $row = is_object($stmt) && method_exists($stmt, 'fetchAll')
            ? ($stmt->fetchAll()[0] ?? [])
            : (array) ($stmt[0] ?? []);
        $this->assertSame('Alice Updated', array_values($row)[0]);
    }

    public function testImportRejectsInvalidRowsPerRow(): void
    {
        $request = new Request('POST', '/api/schii/profiles/import', [], [
            'format' => 'json',
            'data' => [
                ['full_name' => '', 'email' => 'x@example.com', 'active' => true],
            ],
        ]);

        $data = $this->decode($this->endpoint->import($request, ['entity' => 'profiles']));

        $this->assertSame(0, $data['imported']);
        $this->assertSame(1, $data['failed']);
        $this->assertArrayHasKey('full_name', $data['errors'][0]['errors']);
    }

    public function testImportRejectsUnknownFieldsPerRow(): void
    {
        $request = new Request('POST', '/api/schii/profiles/import', [], [
            'format' => 'json',
            'data' => [
                ['full_name' => 'Dave', 'email' => 'dave@example.com', 'is_admin' => true],
            ],
        ]);

        $data = $this->decode($this->endpoint->import($request, ['entity' => 'profiles']));

        $this->assertSame(0, $data['imported']);
        $this->assertSame(1, $data['failed']);
        $this->assertArrayHasKey('is_admin', $data['errors'][0]['errors']);
    }

    public function testImportAcceptsCsvText(): void
    {
        $csv = "full_name,email,active\nFaye,faye@example.com,1\nGrant,grant@example.com,0";
        $request = new Request('POST', '/api/schii/profiles/import', [], [
            'format' => 'csv',
            'data' => $csv,
        ]);

        $data = $this->decode($this->endpoint->import($request, ['entity' => 'profiles']));

        $this->assertSame(2, $data['imported']);
        $this->assertSame(0, $data['failed']);
    }

    public function testImportMissingDataReturns400(): void
    {
        $request = new Request('POST', '/api/schii/profiles/import', [], ['format' => 'json']);
        $this->assertSame(400, $this->endpoint->import($request, ['entity' => 'profiles'])->getStatusCode());
    }

    public function testImportUnknownFormatReturns400(): void
    {
        $request = new Request('POST', '/api/schii/profiles/import', [], [
            'format' => 'xml',
            'data' => [],
        ]);
        $this->assertSame(400, $this->endpoint->import($request, ['entity' => 'profiles'])->getStatusCode());
    }

    public function testImportUnknownEntityReturns404(): void
    {
        $request = new Request('POST', '/api/schii/nope/import', [], ['format' => 'json', 'data' => []]);
        $this->assertSame(404, $this->endpoint->import($request, ['entity' => 'nope'])->getStatusCode());
    }

    // ─────────────────────────── Numeric type validation ───────────────────────────

    private function endpointForMetricRecords(): ModelEndpoint
    {
        $this->db->execute("CREATE TABLE IF NOT EXISTS metric_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL,
            score INTEGER NOT NULL DEFAULT 0,
            ratio REAL
        )");

        return new ModelEndpoint(
            new SchemaRegistry(),
            entityNamespace: 'Phue\\Tests\\Schii\\Stubs'
        );
    }

    public function testStoreRejectsNonNumericValueForIntField(): void
    {
        $endpoint = $this->endpointForMetricRecords();

        $request = new Request('POST', '/api/schii/metric-records', [], [
            'label' => 'A',
            'score' => 'not-a-number',
        ]);

        $response = $endpoint->store($request, ['entity' => 'metric-records']);
        $this->assertSame(422, $response->getStatusCode());

        $data = $this->decode($response);
        $this->assertSame('Validation failed', $data['error']);
        $this->assertSame('Field \'score\' must be numeric', $data['errors']['score']);
    }

    public function testStoreRejectsNonNumericValueForFloatField(): void
    {
        $endpoint = $this->endpointForMetricRecords();

        $request = new Request('POST', '/api/schii/metric-records', [], [
            'label' => 'B',
            'score' => 7,
            'ratio' => '1.2px',
        ]);

        $response = $endpoint->store($request, ['entity' => 'metric-records']);
        $this->assertSame(422, $response->getStatusCode());

        $data = $this->decode($response);
        $this->assertSame('Field \'ratio\' must be numeric', $data['errors']['ratio']);
    }

    public function testStoreAcceptsNumericIntAndFloatValues(): void
    {
        $endpoint = $this->endpointForMetricRecords();

        $request = new Request('POST', '/api/schii/metric-records', [], [
            'label' => 'C',
            'score' => 11,
            'ratio' => 2.5,
        ]);

        $response = $endpoint->store($request, ['entity' => 'metric-records']);
        $this->assertSame(201, $response->getStatusCode());

        $data = $this->decode($response);
        $this->assertSame(11, $data['data']['score']);
        $this->assertSame(2.5, $data['data']['ratio']);
    }
}