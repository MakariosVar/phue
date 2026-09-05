<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Database\Connection;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use App\Models\Product;
use PHPUnit\Framework\TestCase;

class ProductModelTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = new Connection([
            'driver'   => 'sqlite',
            'database' => '/app/database/phue.sqlite',
        ]);
        BaseActiveRecord::setConnection($this->db);

        $this->db->execute("DELETE FROM products WHERE name LIKE 'TestProduct%'");
    }

    protected function tearDown(): void
    {
        $this->db->execute("DELETE FROM products WHERE name LIKE 'TestProduct%'");
    }

    public function testCreateProduct(): void
    {
        $product = Product::create([
            'name' => 'TestProduct Create',
            'price' => 29.99,
            'description' => 'A test product',
        ]);

        $this->assertInstanceOf(Product::class, $product);
        $data = $product->toArray();
        $this->assertNotNull($data['id']);
        $this->assertSame('TestProduct Create', $data['name']);
        $this->assertSame(29.99, $data['price']);
    }

    public function testFindProductById(): void
    {
        $created = Product::create([
            'name' => 'TestProduct Find',
            'price' => 19.99,
        ]);

        $createdId = $created->toArray()['id'];
        $found = Product::find($createdId);
        $this->assertInstanceOf(Product::class, $found);
        $data = $found->toArray();
        $this->assertSame($createdId, $data['id']);
        $this->assertSame('TestProduct Find', $data['name']);
    }

    public function testFindReturnsNullForMissingId(): void
    {
        $product = Product::find(999999);
        $this->assertNull($product);
    }

    public function testAllReturnsArrayOfProducts(): void
    {
        Product::create(['name' => 'TestProduct All1', 'price' => 10.0]);
        Product::create(['name' => 'TestProduct All2', 'price' => 20.0]);

        $all = Product::all();
        $this->assertIsArray($all);
        $this->assertGreaterThanOrEqual(2, count($all));
        $this->assertContainsOnlyInstancesOf(Product::class, $all);
    }

    public function testUpdateProduct(): void
    {
        $product = Product::create([
            'name' => 'TestProduct Update',
            'price' => 15.0,
        ]);

        $productId = $product->toArray()['id'];
        $product->fill(['name' => 'Updated Name', 'price' => 25.0]);
        $product->save();

        $refreshed = Product::find($productId);
        $data = $refreshed->toArray();
        $this->assertSame('Updated Name', $data['name']);
        $this->assertSame(25.0, $data['price']);
    }

    public function testDeleteProduct(): void
    {
        $product = Product::create([
            'name' => 'TestProduct Delete',
            'price' => 5.0,
        ]);

        $id = $product->toArray()['id'];
        $product->delete();

        $found = Product::find($id);
        $this->assertNull($found);
    }

    public function testWhereReturnsCollection(): void
    {
        Product::create(['name' => 'TestProduct Where', 'price' => 30.0]);
        Product::create(['name' => 'Other Product', 'price' => 5.0]);

        $results = Product::where('name', 'TestProduct Where')->get();
        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $data = $results[0]->toArray();
        $this->assertSame('TestProduct Where', $data['name']);
    }

    public function testCreateReturnsInsertedId(): void
    {
        $product = Product::create([
            'name' => 'TestProduct Id',
            'price' => 99.0,
        ]);

        $data = $product->toArray();
        $this->assertIsInt($data['id']);
        $this->assertGreaterThan(0, $data['id']);
    }

public function testDirtyTrackingAfterUpdate(): void
    {
        $product = Product::create([
            'name' => 'TestProduct Dirty',
            'price' => 10.0,
        ]);

        $product->fill(['name' => 'New Name', 'price' => 20.0]);
        $dirty = $product->getDirty();
        $this->assertArrayHasKey('name', $dirty);
        $this->assertArrayHasKey('price', $dirty);
    }
}
