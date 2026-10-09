<?php

declare(strict_types=1);

namespace Phue\Tests\Schii;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Support\UsesIsolatedDatabase;

/**
 * Hardening / edge-case suite for the Schii REST ModelEndpoint.
 *
 * These tests encode the SECURE, strictly-typed behaviour the endpoint must
 * guarantee. Every test in this file is green: each one previously documented a
 * vulnerability that has since been fixed in the source.
 *
 * Two guards carry this file, and the difference matters:
 *
 *  - These tests assert SECURE behaviour. They must stay green. A red one means
 *    a regression was reintroduced.
 *  - The parts of the contract the source still does NOT honour live in
 *    tests/Suite/KnownVulnerabilityTest.php, tagged #[Group('known-vulnerabilities')].
 *    Those assert the CURRENT (insecure) behaviour so they pass, and their
 *    names say so. The ratchet in that file fails if the group grows.
 *
 * A test must never be green for the wrong reason. Where a rule is satisfied
 * incidentally rather than deliberately, the section banner says which
 * mechanism actually enforces it.
 */
#[UI(label: 'Edge Products', icon: 'shield', layout: 'grid')]
class EdgeProduct extends BaseActiveRecord
{
    protected string $table = 'edge_products';

    #[Field(type: 'text', required: true)]
    #[Column(searchable: true, sortable: true)]
    public string $name = '';

    #[Field(type: 'number')]
    #[Column(filterable: true, sortable: true)]
    public float $price = 0.0;

    #[Field(type: 'select')]
    #[Column(filterable: true, options: ['active', 'inactive'])]
    public string $status = 'active';

    /**
     * Declared for realism only — the endpoint never calls this.
     * Kept so the fixture models a fully-annotated model; see the file banner.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required'],
            'price' => ['required', 'numeric'],
        ];
    }
}

#[\PHPUnit\Framework\Attributes\Group('integration')]
final class SchiiEndpointEdgeCasesTest extends TestCase
{
    use UsesIsolatedDatabase;

    private Connection $db;
    private ModelEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = BaseActiveRecord::getConnection();

        // Drop and recreate table for test isolation
        $this->db->execute('DROP TABLE IF EXISTS edge_products');
        $this->db->execute("
            CREATE TABLE edge_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                price REAL NOT NULL,
                status TEXT NOT NULL DEFAULT 'active'
            );
        ");

        $this->endpoint = new ModelEndpoint(new SchemaRegistry());
        $this->endpoint->registerEntity('products', EdgeProduct::class);
    }

    private function decode(mixed $response): array
    {
        $json = $response instanceof Response
            ? $response->getContent()
            : $response->getBody()->getContents();

        return (array) json_decode($json, true);
    }

    private function seed(string $name, float $price, string $status = 'active'): void
    {
        $this->db->execute(
            'INSERT INTO edge_products (name, price, status) VALUES (?, ?, ?)',
            [$name, $price, $status]
        );
    }

    // ------------------------------------------------------------------
    // LIKE wildcard injection — FIXED.
    // Enforced by LIKE escaping in QueryBuilder (addcslashes + ESCAPE '\'),
    // not by the endpoint. Regression guard.
    // ------------------------------------------------------------------

    public function testSearchWithLiteralPercentDoesNotMatchEverything(): void
    {
        $this->seed('Phone', 100.0);
        $this->seed('Tablet', 200.0);
        $this->seed('Laptop', 50.0);

        $response = $this->endpoint->index(
            new Request('GET', '/schii/products', ['q' => '%']),
            'products'
        );

        $data = $this->decode($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $data['meta']['total']);
    }

    public function testSearchWithLiteralUnderscoreDoesNotMatchEverything(): void
    {
        $this->seed('Mouse', 20.0);
        $this->seed('Keyboard', 30.0);

        $response = $this->endpoint->index(
            new Request('GET', '/schii/products', ['q' => '_']),
            'products'
        );

        $data = $this->decode($response);
        $this->assertSame(0, $data['meta']['total']);
    }

    public function testSearchForTextContainingPercentMatchesOnlyLiteralPercent(): void
    {
        $this->seed('Laptop 100%', 100.0);
        $this->seed('Laptop 100X', 110.0);

        $response = $this->endpoint->index(
            new Request('GET', '/schii/products', ['q' => '100%']),
            'products'
        );

        $data = $this->decode($response);
        $this->assertSame(1, $data['meta']['total']);
        $this->assertSame('Laptop 100%', $data['data'][0]['name']);
    }

    // ------------------------------------------------------------------
    // Mass assignment / primary key protection — FIXED.
    // Enforced by ModelEndpoint::extractInputData(), which intersects the payload
    // with writable&&!readonly schema fields and then unsets the primary key.
    // ------------------------------------------------------------------

    public function testStoreIgnoresClientSuppliedPrimaryKey(): void
    {
        $response = $this->endpoint->store(
            new Request('POST', '/schii/products', [], [
                'name' => 'Hacked',
                'price' => 10.0,
                'status' => 'active',
                'id' => 999,
            ]),
            'products'
        );

        $data = $this->decode($response);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertNotSame(999, $data['data']['id']);

        $stored = $this->db->query('SELECT id FROM edge_products WHERE id = 999')->fetchAll();
        $this->assertEmpty($stored);
    }

    public function testUpdateIgnoresClientSuppliedPrimaryKey(): void
    {
        $this->seed('Original', 10.0);
        $id = (int) $this->db->lastInsertId();

        try {
            $response = $this->endpoint->update(
                new Request('PUT', "/schii/products/{$id}", [], [
                    'name' => 'Renamed',
                    'price' => 20.0,
                    'id' => 777,
                ]),
                ['entity' => 'products', 'id' => $id]
            );
        } catch (\Throwable $e) {
            $this->fail('update() must not throw when id is mass-assigned, got ' . get_class($e) . ': ' . $e->getMessage());
        }

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertSame((string) $id, (string) $data['data']['id']);

        $result = $this->db->query('SELECT id FROM edge_products WHERE id = 777')->fetchAll();
        $this->assertEmpty($result);
    }

    // ------------------------------------------------------------------
    // Unknown-field handling: strict rejection with 422
    // ------------------------------------------------------------------

    public function testStoreWithUnknownFieldReturns422(): void
    {
        try {
            $response = $this->endpoint->store(
                new Request('POST', '/schii/products', [], [
                    'name' => 'Valid',
                    'price' => 10.0,
                    'status' => 'active',
                    'hacker_field' => 1,
                ]),
                'products'
            );
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            $this->fail('store() must not throw on unknown fields, got ' . get_class($e) . ': ' . $e->getMessage());
        }

        $data = $this->decode($response);
        $this->assertSame(422, $status);
        $this->assertSame('Validation failed', $data['error']);
        $this->assertArrayHasKey('hacker_field', $data['errors']);

        $row = $this->db->query('SELECT COUNT(*) AS cnt FROM edge_products')->fetch();
        $this->assertSame(0, (int) $row['cnt']);
    }

    // ------------------------------------------------------------------
    // Numeric validation — but NOT via Model::rules().
    //
    // This passes because ModelEndpoint::validateData() implicitly appends the
    // `numeric` rule when it sees #[Field(type: 'number'|'int'|'float')]. The
    // model's own rules() method is never invoked anywhere in src/ — it is dead
    // API. See KnownVulnerabilityTest::testModelRulesMethodIsNeverInvoked().
    // ------------------------------------------------------------------

    public function testStoreRejectsNonNumericPrice(): void
    {
        $response = $this->endpoint->store(
            new Request('POST', '/schii/products', [], [
                'name' => 'Gadget',
                'price' => 'abc',
                'status' => 'active',
            ]),
            'products'
        );

        $data = $this->decode($response);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Validation failed', $data['error']);
    }

    // ------------------------------------------------------------------
    // Unknown entity routing — FIXED.
    // index() resolves the entity against the SchemaRegistry and returns 404.
    // ------------------------------------------------------------------

    public function testUnregisteredEntityReturns404NotFound(): void
    {
        try {
            $response = $this->endpoint->index(
                new Request('GET', '/schii/unknown'),
                'unknown'
            );
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            $this->fail('Unregistered entity must yield a 404 response, got ' . get_class($e) . ': ' . $e->getMessage());
        }

        $this->assertSame(404, $status);
    }

    // ------------------------------------------------------------------
    // Regression guards (behaviour that was already correct)
    // ------------------------------------------------------------------

    public function testStoreMissingRequiredFieldReturns422(): void
    {
        $response = $this->endpoint->store(
            new Request('POST', '/schii/products', [], [
                'price' => 10.0,
                'status' => 'active',
            ]),
            'products'
        );

        $data = $this->decode($response);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Validation failed', $data['error']);
    }

    public function testPaginationClampsGarbageInput(): void
    {
        $this->seed('A', 1.0);
        $this->seed('B', 2.0);
        $this->seed('C', 3.0);

        $response = $this->endpoint->index(
            new Request('GET', '/schii/products', [
                'page' => 'abc',
                'limit' => '9999',
            ]),
            'products'
        );

        $meta = $this->decode($response)['meta'];
        $this->assertSame(1, $meta['page']);
        $this->assertSame(100, $meta['limit']);
        $this->assertSame(3, $meta['total']);
    }

    public function testSortDirectionIsCaseInsensitiveAndRestrictive(): void
    {
        $this->seed('Alpha', 1.0);
        $this->seed('Beta', 2.0);

        $response = $this->endpoint->index(
            new Request('GET', '/schii/products', [
                'sort' => 'price',
                'direction' => 'desc',
            ]),
            'products'
        );

        $data = $this->decode($response)['data'];
        $this->assertSame('Beta', $data[0]['name']);

        $response = $this->endpoint->index(
            new Request('GET', '/schii/products', [
                'sort' => 'price',
                'direction' => 'sideways',
            ]),
            'products'
        );

        $data = $this->decode($response)['data'];
        $this->assertSame('Alpha', $data[0]['name']);
    }

    public function testInvalidSortFieldIsIgnored(): void
    {
        $this->seed('Alpha', 1.0);
        $this->seed('Beta', 2.0);

        $response = $this->endpoint->index(
            new Request('GET', '/schii/products', [
                'sort' => 'price; DROP TABLE edge_products;--',
            ]),
            'products'
        );

        $data = $this->decode($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $data['meta']['total']);

        $row = $this->db->query('SELECT COUNT(*) AS cnt FROM edge_products')->fetch();
        $this->assertSame(2, (int) $row['cnt']);
    }
}