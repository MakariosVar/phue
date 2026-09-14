<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Schema;

use PHPUnit\Framework\TestCase;
use Phue\Schii\Schema\ColumnDefinition;

/**
 * ColumnDefinition: defaulting, array round-trip and value hardening.
 *
 * @package Phue\Tests\Schii\Schema
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ColumnDefinitionTest extends TestCase
{
    public function testConstructorRequiresOnlyNameAndType(): void
    {
        $column = new ColumnDefinition(name: 'id', type: 'int');

        $this->assertSame('id', $column->name);
        $this->assertSame('int', $column->type);
        $this->assertTrue($column->nullable);
        $this->assertNull($column->default);
        $this->assertFalse($column->primaryKey);
        $this->assertFalse($column->autoIncrement);
        $this->assertFalse($column->indexed);
        $this->assertSame(255, $column->length);
    }

    public function testFromArrayAppliesDefaultsForMissingKeys(): void
    {
        $column = ColumnDefinition::fromArray(['name' => 'email', 'type' => 'text']);

        $this->assertSame('email', $column->name);
        $this->assertSame('text', $column->type);
        $this->assertTrue($column->nullable);
        $this->assertFalse($column->primaryKey);
        $this->assertSame(255, $column->length);
    }

    public function testToArrayExposesSerializableShape(): void
    {
        $column = new ColumnDefinition(
            name: 'price',
            type: 'float',
            nullable: false,
            default: 0.0,
            primaryKey: false,
            autoIncrement: false,
            indexed: true,
            length: 128,
        );

        $this->assertSame([
            'name' => 'price',
            'type' => 'float',
            'nullable' => false,
            'default' => 0.0,
            'primaryKey' => false,
            'autoIncrement' => false,
            'indexed' => true,
            'length' => 128,
        ], $column->toArray());
    }

    public function testRoundTripPreservesAllValuesIncludingNullDefault(): void
    {
        $column = new ColumnDefinition(
            name: 'note',
            type: 'text',
            nullable: true,
            default: null,
            primaryKey: false,
            autoIncrement: false,
            indexed: false,
            length: 255,
        );

        $this->assertSame($column->toArray(), ColumnDefinition::fromArray($column->toArray())->toArray());
    }

    public function testFromArrayHardensScalarTypes(): void
    {
        $column = ColumnDefinition::fromArray([
            'name' => 'tags',
            'type' => 'text',
            'nullable' => 0,
            'primaryKey' => '1',
            'autoIncrement' => 1,
            'indexed' => '',
            'length' => '64',
        ]);

        $this->assertFalse($column->nullable);
        $this->assertTrue($column->primaryKey);
        $this->assertTrue($column->autoIncrement);
        $this->assertFalse($column->indexed);
        $this->assertSame(64, $column->length);
    }
}