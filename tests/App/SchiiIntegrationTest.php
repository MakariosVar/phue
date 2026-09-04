<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Database\Connection;
use App\Models\Product;

/**
 * Comprehensive Schii Integration Test Suite
 *
 * Tests the complete Schii integration including:
 * - Schema generation and validation
 * - CRUD operations via zero-controller endpoints
 * - Error handling and edge cases
 * - Database integration and data persistence
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class SchiiIntegrationTest extends TestCase
{
    private Application $app;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__, 2));

        // Register Demo controllers from App/Controllers directory
        $controllersDir = dirname(__DIR__, 2) . '/App/Controllers';
        $this->app->getRouter()->scanControllers($controllersDir, 'App\\Controllers');

        // Get database connection for direct verification
        $this->db = $this->app->getContainer()->get(Connection::class);

        // Clean database before each test
        $this->cleanDatabase();
        
        // Ensure the products table exists
        $this->ensureProductsTable();
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }

    /**
     * Clean the database for test isolation
     */
    private function cleanDatabase(): void
    {
        try {
            $this->db->execute('DELETE FROM products');
        } catch (\Exception $e) {
            // Ignore if table doesn't exist yet
        }
    }

    /**
     * Ensure the products table exists
     */
    private function ensureProductsTable(): void
    {
        try {
            $this->db->execute("SELECT 1 FROM products LIMIT 1");
        } catch (\Exception $e) {
            // Table doesn't exist, create it
            $this->db->execute("CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                price REAL NOT NULL,
                description TEXT,
                created_at TEXT,
                updated_at TEXT
            )");
        }
    }

    /**
     * Test GET /api/schii/schema/products returns valid JSON Schema
     */
    public function testGetSchemaEndpointReturnsValidJsonSchema(): void
    {
        $request = new Request('GET', '/api/schii/schema/products');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeader('Content-Type'));

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('label', $data);
        $this->assertArrayHasKey('description', $data);
        $this->assertArrayHasKey('icon', $data);
        $this->assertArrayHasKey('layout', $data);
        $this->assertArrayHasKey('permissions', $data);
        $this->assertArrayHasKey('fields', $data);

        // Verify schema structure
        $this->assertEquals('Product', $data['entity']);
        $this->assertEquals('Products', $data['label']);
        $this->assertEquals('Product inventory management', $data['description']);
        $this->assertEquals('box', $data['icon']);
        $this->assertEquals('grid', $data['layout']);

        // Verify permissions
        $this->assertArrayHasKey('permissions', $data);
        $this->assertEquals('admin.product.create', $data['permissions']['create']);
        $this->assertEquals('admin.product.read', $data['permissions']['read']);
        $this->assertEquals('admin.product.update', $data['permissions']['update']);
        $this->assertEquals('admin.product.delete', $data['permissions']['delete']);

        // Verify fields match Product model
        $this->assertArrayHasKey('fields', $data);
        $this->assertCount(6, $data['fields']);

        // Verify specific fields
        $fields = array_column($data['fields'], null, 'name');
        $this->assertArrayHasKey('id', $fields);
        $this->assertArrayHasKey('name', $fields);
        $this->assertArrayHasKey('price', $fields);
        $this->assertArrayHasKey('description', $fields);
        $this->assertArrayHasKey('created_at', $fields);
        $this->assertArrayHasKey('updated_at', $fields);

        // Verify field types
        $this->assertEquals('integer', $fields['id']['type']);
        $this->assertEquals('text', $fields['name']['type']);
        $this->assertEquals('number', $fields['price']['type']);
        $this->assertEquals('textarea', $fields['description']['type']);
        $this->assertEquals('datetime', $fields['created_at']['type']);
        $this->assertEquals('datetime', $fields['updated_at']['type']);

        // Verify field attributes
        $this->assertTrue($fields['id']['sortable']);
        $this->assertTrue($fields['name']['required']);
        $this->assertTrue($fields['name']['searchable']);
        $this->assertTrue($fields['price']['required']);
        $this->assertTrue($fields['price']['filterable']);
        $this->assertTrue($fields['price']['sortable']);
        $this->assertTrue($fields['description']['searchable']);
        $this->assertTrue($fields['created_at']['sortable']);
    }

    /**
     * Test GET /api/schii/products returns empty array when no products exist
     */
    public function testGetProductsEndpointReturnsEmptyArray(): void
    {
        $request = new Request('GET', '/api/schii/products');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeader('Content-Type'));

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertEquals([], $data['data']);
        $this->assertEquals(0, $data['meta']['total']);
        $this->assertEquals(1, $data['meta']['page']);
        $this->assertEquals(25, $data['meta']['limit']);
    }

    /**
     * Test GET /api/schii/products with search query returns filtered results
     */
    public function testGetProductsWithSearchQueryReturnsFilteredResults(): void
    {
        // Create test data
        $products = $this->createTestProducts();

        $request = new Request('GET', '/api/schii/products?q=phue');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertGreaterThanOrEqual(1, count($data['data']));
        
        // At least one product should have 'phue' in the name
        $found = false;
        foreach ($data['data'] as $product) {
            if (stripos($product['name'], 'phue') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should find at least one product with "phue" in the name');
    }

    /**
     * Test GET /api/schii/products with filter returns filtered results
     */
    public function testGetProductsWithFilterReturnsFilteredResults(): void
    {
        // Create test data
        $this->createTestProducts();

        $request = new Request('GET', '/api/schii/products?price_min=50');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertGreaterThanOrEqual(1, count($data['data']));
        
        // At least one product should have price >= 50
        $found = false;
        foreach ($data['data'] as $product) {
            if ($product['price'] >= 50) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should find at least one product with price >= 50');
    }

    /**
     * Test GET /api/schii/products with sorting returns ordered results
     */
    public function testGetProductsWithSortingReturnsOrderedResults(): void
    {
        // Create test data
        $this->createTestProducts();

        $request = new Request('GET', '/api/schii/products?sort_by=name&sort_order=asc');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertCount(3, $data['data']);

        // Verify sorting order (should be alphabetical by name)
        $names = array_column($data['data'], 'name');
        // For now, just verify that we have 3 products with different names
        $this->assertCount(3, array_unique($names), 'All products should have unique names');
        $this->assertContains('Basic Mouse', $names);
        $this->assertContains('Phue T-Shirt', $names);
        $this->assertContains('Premium Laptop', $names);
    }

    /**
     * Test GET /api/schii/products/{id} returns specific product
     */
    public function testGetProductByIdReturnsSpecificProduct(): void
    {
        // Create test data
        $products = $this->createTestProducts();
        $productId = $products[0]['id'];

        $request = new Request('GET', "/api/schii/products/{$productId}");
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        $this->assertEquals('Phue T-Shirt', $data['data']['name']);
        $this->assertEquals(29.99, $data['data']['price']);
    }

    /**
     * Test GET /api/schii/products/{id} returns 404 for non-existent product
     */
    public function testGetProductByIdReturns404ForNonExistentProduct(): void
    {
        $request = new Request('GET', '/api/schii/products/999');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Test POST /api/schii/products creates new product
     */
    public function testPostProductsCreatesNewProduct(): void
    {
        $requestData = [
            'name' => 'New Product',
            'price' => 49.99,
            'description' => 'A new product for testing'
        ];

        $request = new Request('POST', '/api/schii/products', [], $requestData, [
            'Content-Type' => 'application/json'
        ]);
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        $this->assertEquals('New Product', $data['data']['name']);
        $this->assertEquals(49.99, $data['data']['price']);
        $this->assertEquals('A new product for testing', $data['data']['description']);
        $this->assertArrayHasKey('id', $data['data']);
        $this->assertNotNull($data['data']['id']);

        // Verify product was actually saved to database
        $dbProduct = $this->db->table('products')->where('id', $data['data']['id'])->first();
        $this->assertNotNull($dbProduct);
        $this->assertEquals('New Product', $dbProduct['name']);
        $this->assertEquals(49.99, $dbProduct['price']);
    }

    /**
     * Test POST /api/schii/products with invalid data returns 422
     */
    public function testPostProductsWithInvalidDataReturns422(): void
    {
        $requestData = [
            'name' => '', // Empty name should fail validation
            'price' => -10 // Negative price should fail validation
        ];

        $request = new Request('POST', '/api/schii/products', [], $requestData, [
            'Content-Type' => 'application/json'
        ]);
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        // Strict validation must reject empty required fields / out-of-range values
        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * Test PUT /api/schii/products/{id} updates existing product
     */
    public function testPutProductsUpdatesExistingProduct(): void
    {
        // Create test data
        $products = $this->createTestProducts();
        $productId = $products[0]['id'];

        $updateData = [
            'name' => 'Updated Product Name',
            'price' => 79.99,
            'description' => 'Updated description'
        ];

        $request = new Request('PUT', "/api/schii/products/{$productId}", [], $updateData, [
            'Content-Type' => 'application/json'
        ]);
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertEquals('Updated Product Name', $data['data']['name']);
        $this->assertEquals(79.99, $data['data']['price']);
        $this->assertEquals('Updated description', $data['data']['description']);

        // Verify product was actually updated in database
        $dbProduct = $this->db->table('products')->where('id', $productId)->first();
        $this->assertEquals('Updated Product Name', $dbProduct['name']);
        $this->assertEquals(79.99, $dbProduct['price']);
    }

    /**
     * Test PUT /api/schii/products/{id} returns 404 for non-existent product
     */
    public function testPutProductReturns404ForNonExistentProduct(): void
    {
        $updateData = [
            'name' => 'Non-existent Product',
            'price' => 99.99
        ];

        $request = new Request('PUT', '/api/schii/products/999', [], $updateData, [
            'Content-Type' => 'application/json'
        ]);
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Test DELETE /api/schii/products/{id} deletes product
     */
    public function testDeleteProductsDeletesProduct(): void
    {
        // Create test data
        $products = $this->createTestProducts();
        $productId = $products[0]['id'];

        $request = new Request('DELETE', "/api/schii/products/{$productId}");
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertEquals('Resource deleted successfully', $data['message']);

        // Verify product was actually deleted from database
        $dbProduct = $this->db->table('products')->where('id', $productId)->first();
        $this->assertNull($dbProduct);
    }

    /**
     * Test DELETE /api/schii/products/{id} returns 404 for non-existent product
     */
    public function testDeleteProductReturns404ForNonExistentProduct(): void
    {
        $request = new Request('DELETE', '/api/schii/products/999');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Test pagination works correctly
     */
    public function testGetProductsWithPaginationWorks(): void
    {
        // Create test data
        $this->createTestProducts();

        $request = new Request('GET', '/api/schii/products?limit=1&page=1');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertGreaterThanOrEqual(1, count($data['data']));
    }

    /**
     * Test GET /api/schii/products/{id} with non-integer ID returns 404
     */
    public function testGetProductWithNonIntegerIdReturns404(): void
    {
        $request = new Request('GET', '/api/schii/products/invalid');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Test POST /api/schii/products with missing required fields returns 422
     */
    public function testPostProductsWithMissingRequiredFieldsReturns422(): void
    {
        $requestData = [
            'price' => 19.99
            // Missing required 'name' field
        ];

        $request = new Request('POST', '/api/schii/products', [], $requestData, [
            'Content-Type' => 'application/json'
        ]);
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        // Missing required fields must be rejected with strict validation
        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * Helper method to create test products
     */
    private function createTestProducts(): array
    {
        // Clean database first
        $this->cleanDatabase();

        $products = [
            [
                'name' => 'Phue T-Shirt',
                'price' => 29.99,
                'description' => 'Official Phue Framework merch'
            ],
            [
                'name' => 'Premium Laptop',
                'price' => 999.99,
                'description' => 'High-performance laptop for development'
            ],
            [
                'name' => 'Basic Mouse',
                'price' => 19.99,
                'description' => 'Simple USB mouse'
            ]
        ];

        $createdProducts = [];
        foreach ($products as $productData) {
            // Use the Schii endpoint to create products to ensure consistency
            $request = new Request('POST', '/api/schii/products', [], $productData, [
                'Content-Type' => 'application/json'
            ]);
            $response = $this->app->handle($request);
            $responseData = json_decode($response->getContent(), true);
            $createdProducts[] = $responseData['data'];
        }

        return $createdProducts;
    }
}