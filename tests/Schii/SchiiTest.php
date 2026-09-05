<?php

namespace Phue\Tests\Schii;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Schii\Attribute\Field;
use Phue\Schii\Attribute\UI;
use Phue\Schii\Endpoint\ModelEndpoint;
use Phue\Schii\Schema\SchemaRegistry;

/**
 * Stub ActiveRecord model used for testing Schii attributes and endpoints.
 */
#[UI(label: 'Products', icon: 'box', layout: 'grid')]
class StubProduct extends BaseActiveRecord
{
    /**
     * Table name property must remain non-static to match BaseActiveRecord inheritance.
     */
    protected string $table = 'stub_products';

    #[Field(type: 'text', searchable: true, sortable: true, required: true)]
    public string $name = '';

    #[Field(type: 'number', filterable: true, sortable: true)]
    public float $price = 0.0;

    #[Field(type: 'select', filterable: true, options: ['active', 'inactive'])]
    public string $status = 'active';

    public function rules(): array
    {
        return [
            'name' => ['required'],
            'price' => ['required', 'numeric'],
        ];
    }
}

#[\PHPUnit\Framework\Attributes\Group('integration')]
class SchiiTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Initialize SQLite in-memory database for testing
        $this->db = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        BaseActiveRecord::setConnection($this->db);

        $this->db->execute("
            CREATE TABLE stub_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                price REAL NOT NULL,
                status TEXT NOT NULL DEFAULT 'active'
            );
        ");
    }

    public function testSchemaRegistryParsesAttributes(): void
    {
        $registry = new SchemaRegistry();
        $schema = $registry->get(StubProduct::class);

        $this->assertSame('StubProduct', $schema->entity);
        $this->assertSame('Products', $schema->label);
        $this->assertSame('box', $schema->icon);
        $this->assertCount(3, $schema->fields);

        $array = $schema->toArray();
        $this->assertSame('Products', $array['label']);
        $this->assertTrue($array['fields'][0]['searchable']);
        $this->assertTrue($array['fields'][1]['filterable']);
    }

    public function testModelEndpointSchemaResponse(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', StubProduct::class);

        $request = new Request('GET', '/schii/schema/products');
        $response = $endpoint->schema($request, 'products');
        $data = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Products', $data['label']);
        $this->assertCount(3, $data['fields']);
    }

    public function testModelEndpointCrudOperations(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', StubProduct::class);

        // 1. STORE Request
        $storeReq = new Request('POST', '/schii/products', [], ['name' => 'Laptop', 'price' => 1200.00, 'status' => 'active']);
        $storeRes = $endpoint->store($storeReq, 'products');
        $storeData = json_decode($storeRes->getContent(), true);

        $this->assertSame(201, $storeRes->getStatusCode());
        $this->assertSame('Laptop', $storeData['data']['name']);
        $id = $storeData['data']['id'];

        // 2. SHOW Request
        $showRes = $endpoint->show($storeReq, ['entity' => 'products', 'id' => $id]);
        $showData = json_decode($showRes->getContent(), true);
        $this->assertSame('Laptop', $showData['data']['name']);

        // 3. INDEX Request with search query
        $indexReq = new Request('GET', '/schii/products', ['q' => 'Lap']);
        $indexRes = $endpoint->index($indexReq, 'products');
        $indexData = json_decode($indexRes->getContent(), true);

        $this->assertSame(200, $indexRes->getStatusCode());
        $this->assertCount(1, $indexData['data']);
        $this->assertSame(1, $indexData['meta']['total']);

        // 4. UPDATE Request
        $updateReq = new Request('PUT', "/schii/products/{$id}", [], ['name' => 'Pro Laptop', 'price' => 1500.00]);
        $updateRes = $endpoint->update($updateReq, ['entity' => 'products', 'id' => $id]);
        $updateData = json_decode($updateRes->getContent(), true);

        $this->assertSame(200, $updateRes->getStatusCode());
        $this->assertSame('Pro Laptop', $updateData['data']['name']);

        // 5. DELETE Request
        $deleteRes = $endpoint->delete($storeReq, ['entity' => 'products', 'id' => $id]);
        $this->assertSame(200, $deleteRes->getStatusCode());

        // Verify Deletion
        $showAfterDelete = $endpoint->show($storeReq, ['entity' => 'products', 'id' => $id]);
        $this->assertSame(404, $showAfterDelete->getStatusCode());
    }

    public function testStoreUnknownEntityReturns404(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);

        $req = new Request('POST', '/schii/unknown', [], ['name' => 'test']);
        $res = $endpoint->store($req, 'unknown');

        $this->assertSame(404, $res->getStatusCode());
    }

    public function testStoreValidationFailsForUnknownField(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', StubProduct::class);

        $req = new Request('POST', '/schii/products', [], ['name' => 'Test', 'price' => 100, 'unknown_field' => 'bad']);
        $res = $endpoint->store($req, 'products');

        $this->assertSame(422, $res->getStatusCode());
    }

    public function testStoreValidationFailsForMissingRequiredField(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', StubProduct::class);

        $req = new Request('POST', '/schii/products', [], ['price' => 100]);
        $res = $endpoint->store($req, 'products');

        $this->assertSame(422, $res->getStatusCode());
    }

    public function testStoreValidationFailsForNonNumericPrice(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', StubProduct::class);

        $req = new Request('POST', '/schii/products', [], ['name' => 'Test', 'price' => 'abc']);
        $res = $endpoint->store($req, 'products');

        $this->assertSame(422, $res->getStatusCode());
    }

    public function testUpdateUnknownEntityReturns404(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);

        $req = new Request('PUT', '/schii/unknown/1', [], ['name' => 'Test']);
        $res = $endpoint->update($req, ['entity' => 'unknown', 'id' => 1]);

        $this->assertSame(404, $res->getStatusCode());
    }

    public function testUpdateValidationFailsForUnknownField(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', StubProduct::class);

        // Create a record first
        $storeReq = new Request('POST', '/schii/products', [], ['name' => 'Test', 'price' => 100, 'status' => 'active']);
        $storeRes = $endpoint->store($storeReq, 'products');
        $storeData = json_decode($storeRes->getContent(), true);
        $id = $storeData['data']['id'];

        // Now try to update with unknown field
        $req = new Request('PUT', "/schii/products/{$id}", [], ['name' => 'Test', 'unknown_field' => 'bad']);
        $res = $endpoint->update($req, ['entity' => 'products', 'id' => $id]);

        $this->assertSame(422, $res->getStatusCode());
    }

    public function testIndexUnknownEntityReturns404(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);

        $req = new Request('GET', '/schii/unknown');
        $res = $endpoint->index($req, 'unknown');

        $this->assertSame(404, $res->getStatusCode());
    }
}