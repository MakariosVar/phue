<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Endpoint;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;

#[Group('integration')]
final class ModelEndpointFilterSortTest extends TestCase
{
    private Connection $db;
    private ModelEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = BaseActiveRecord::getConnection();

        // Drop and recreate table for test isolation
        $this->db->execute('DROP TABLE IF EXISTS profiles');
        $this->db->execute("CREATE TABLE profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 0
        )");

        $this->db->execute("INSERT INTO profiles (full_name, email, active) VALUES ('Alice Wonderland', 'alice@example.com', 1)");
        $this->db->execute("INSERT INTO profiles (full_name, email, active) VALUES ('Bob Builder', 'bob@example.com', 0)");
        $this->db->execute("INSERT INTO profiles (full_name, email, active) VALUES ('Charlie Chaplin', 'charlie@example.com', 1)");
        $this->db->execute("INSERT INTO profiles (full_name, email, active) VALUES ('David Hasselhoff', 'david@example.com', 0)");

        $this->endpoint = new ModelEndpoint(
            new SchemaRegistry(),
            entityNamespace: 'Phue\\Tests\\Schii\\Stubs'
        );
    }

    private function decode(Response $response): array
    {
        return (array) json_decode($response->getContent(), true);
    }

    public function testFlatFilteringOnFilterableField(): void
    {
        // Profiles stub: 'active' is filterable
        $req = new Request('GET', '/api/schii/profile', ['active' => '1']);
        $res = $this->endpoint->index($req, 'profile');
        $data = $this->decode($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(2, $data['data']);
        $names = array_column($data['data'], 'full_name');
        $this->assertContains('Alice Wonderland', $names);
        $this->assertContains('Charlie Chaplin', $names);
    }

    public function testArraySyntaxFilteringOnFilterableField(): void
    {
        // Using filter[active]=0
        $req = new Request('GET', '/api/schii/profile', [
            'filter' => ['active' => '0'],
        ]);
        $res = $this->endpoint->index($req, 'profile');
        $data = $this->decode($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(2, $data['data']);
        $names = array_column($data['data'], 'full_name');
        $this->assertContains('Bob Builder', $names);
        $this->assertContains('David Hasselhoff', $names);
    }

    public function testNonFilterableFieldIsIgnored(): void
    {
        // 'full_name' is not filterable in Profile stub
        $req = new Request('GET', '/api/schii/profile', [
            'filter' => ['full_name' => 'Alice Wonderland'],
        ]);
        $res = $this->endpoint->index($req, 'profile');
        $data = $this->decode($res);

        // Should return all 4 records because filter on full_name is ignored
        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(4, $data['data']);
    }

    public function testDynamicSortingWithSeparateParams(): void
    {
        // 'full_name' is sortable
        $req = new Request('GET', '/api/schii/profile', [
            'sort' => 'full_name',
            'direction' => 'DESC',
        ]);
        $res = $this->endpoint->index($req, 'profile');
        $data = $this->decode($res);

        $this->assertSame(200, $res->getStatusCode());
        $names = array_column($data['data'], 'full_name');
        $this->assertSame(['David Hasselhoff', 'Charlie Chaplin', 'Bob Builder', 'Alice Wonderland'], $names);
    }

    public function testDynamicSortingWithCombinedColonSyntax(): void
    {
        // 'sort=full_name:asc'
        $req = new Request('GET', '/api/schii/profile', ['sort' => 'full_name:asc']);
        $res = $this->endpoint->index($req, 'profile');
        $data = $this->decode($res);

        $this->assertSame(200, $res->getStatusCode());
        $names = array_column($data['data'], 'full_name');
        $this->assertSame(['Alice Wonderland', 'Bob Builder', 'Charlie Chaplin', 'David Hasselhoff'], $names);

        // 'sort=full_name:desc'
        $reqDesc = new Request('GET', '/api/schii/profile', ['sort' => 'full_name:desc']);
        $resDesc = $this->endpoint->index($reqDesc, 'profile');
        $dataDesc = $this->decode($resDesc);

        $this->assertSame(200, $resDesc->getStatusCode());
        $namesDesc = array_column($dataDesc['data'], 'full_name');
        $this->assertSame(['David Hasselhoff', 'Charlie Chaplin', 'Bob Builder', 'Alice Wonderland'], $namesDesc);
    }

    public function testNonSortableFieldIsIgnored(): void
    {
        // 'email' is not sortable in Profile stub
        $req = new Request('GET', '/api/schii/profile', ['sort' => 'email:desc']);
        $res = $this->endpoint->index($req, 'profile');
        $data = $this->decode($res);

        $this->assertSame(200, $res->getStatusCode());
        // Default order without sorting applied
        $this->assertCount(4, $data['data']);
    }

    public function testGlobalSearchFiltersRecords(): void
    {
        $req = new Request('GET', '/api/schii/profile', ['q' => 'chaplin']);
        $res = $this->endpoint->index($req, 'profile');
        $data = $this->decode($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(1, $data['data']);
        $this->assertSame('Charlie Chaplin', $data['data'][0]['full_name']);
    }
}
