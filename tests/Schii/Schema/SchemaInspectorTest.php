<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Schema;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Schii\Schema\ColumnDefinition;
use Phue\Schii\Schema\SchemaInspector;

/**
 * SchemaInspector against a real in-memory SQLite database.
 *
 * @package Phue\Tests\Schii\Schema
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class SchemaInspectorTest extends TestCase
{
    private Connection $db;
    private SchemaInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->inspector = new SchemaInspector($this->db);

        $this->db->getPdo()->exec(
            'CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku VARCHAR(50) NOT NULL,
                price REAL,
                active BOOLEAN NOT NULL DEFAULT 1,
                released_at DATE,
                created_at DATETIME
            )'
        );
    }

    public function testTableExistsDetectsExistingAndMissingTables(): void
    {
        $this->assertTrue($this->inspector->tableExists('products'));
        $this->assertFalse($this->inspector->tableExists('does_not_exist'));
    }

    public function testInspectReturnsColumnDefinitionsPerColumn(): void
    {
        $columns = $this->inspector->inspect('products');

        $this->assertContainsOnlyInstancesOf(ColumnDefinition::class, $columns);
        $this->assertSame([
            'id', 'sku', 'price', 'active', 'released_at', 'created_at',
        ], array_keys($columns));
    }

    public function testInspectMapsPrimaryKeyAutoIncrement(): void
    {
        $id = $this->inspector->inspect('products')['id'];

        $this->assertSame('id', $id->name);
        $this->assertSame('int', $id->type);
        $this->assertTrue($id->primaryKey);
        $this->assertTrue($id->autoIncrement);
    }

    public function testInspectNormalizesSqliteTypes(): void
    {
        $columns = $this->inspector->inspect('products');

        $this->assertSame('varchar', $columns['sku']->type);
        $this->assertSame(50, $columns['sku']->length);
        $this->assertFalse($columns['sku']->nullable);
        $this->assertSame('float', $columns['price']->type);
        $this->assertTrue($columns['price']->nullable);
        $this->assertSame('boolean', $columns['active']->type);
        $this->assertSame('date', $columns['released_at']->type);
        $this->assertSame('datetime', $columns['created_at']->type);
    }

    public function testInspectCapturesNonNullableFlag(): void
    {
        $columns = $this->inspector->inspect('products');

        $this->assertFalse($columns['sku']->nullable);
        $this->assertFalse($columns['active']->nullable);
    }

    public function testInspectOnMissingTableReturnsEmpty(): void
    {
        $this->assertSame([], $this->inspector->inspect('nope'));
    }
}