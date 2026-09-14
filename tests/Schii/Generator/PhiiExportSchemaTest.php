<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Generator;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phue\Schii\Generator\PhiiExportField;
use Phue\Schii\Generator\PhiiExportSchema;
use Phue\Schii\Schema\FieldSchema;
use Phue\Schii\Schema\ModelSchema;
use Phue\Tests\Schii\Stubs\DataMapperEntity;
use Phue\Tests\Schii\Stubs\User;

/**
 * PhiiExportSchema: fromModelSchema conversion, array round-trip fidelity and
 * non-ActiveRecord rejection.
 *
 * @package Phue\Tests\Schii\Generator
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhiiExportSchemaTest extends TestCase
{
    private function stubModelSchema(): ModelSchema
    {
        return new ModelSchema(
            entity: 'TestUser',
            className: User::class,
            label: 'Test User',
            icon: 'user',
            layout: 'grid',
            description: 'A test user',
            fields: [
                'id' => new FieldSchema(
                    name: 'id',
                    type: 'text',
                    label: 'ID',
                    widget: 'input',
                    column: false,
                    required: false,
                    searchable: false,
                    filterable: false,
                    sortable: false,
                ),
                'name' => new FieldSchema(
                    name: 'name',
                    type: 'text',
                    label: 'Name',
                    widget: 'input',
                    column: true,
                    required: true,
                    searchable: true,
                    filterable: false,
                    sortable: true,
                ),
            ],
            permissions: [
                'create' => 'admin.testuser.create',
                'read' => 'admin.testuser.read',
                'update' => 'admin.testuser.update',
                'delete' => 'admin.testuser.delete',
            ],
        );
    }

    public function testFromModelSchemaMapsCoreProperties(): void
    {
        $export = PhiiExportSchema::fromModelSchema($this->stubModelSchema(), User::class, 'admin');

        $this->assertSame('TestUser', $export->entity);
        $this->assertSame(User::class, $export->className);
        $this->assertSame('Test User', $export->label);
        $this->assertSame('user', $export->icon);
        $this->assertSame('Test User', $export->label);
        $this->assertSame('users', $export->table);
        $this->assertSame('admin', $export->module);
        $this->assertSame('grid', $export->layout);
        $this->assertSame('A test user', $export->description);
        $this->assertSame('Phue\Tests\Schii\Stubs', $export->namespace);
        $this->assertSame('admin.testuser.create', $export->permissions['create']);
        $this->assertSame('admin.testuser.delete', $export->permissions['delete']);
    }

    public function testFromModelSchemaMapsFieldsToExportFields(): void
    {
        $export = PhiiExportSchema::fromModelSchema($this->stubModelSchema(), User::class);

        $this->assertCount(2, $export->fields);
        $this->assertContainsOnlyInstancesOf(PhiiExportField::class, $export->fields);

        $nameField = $export->fields[1];
        $this->assertSame('name', $nameField->name);
        $this->assertTrue($nameField->required);
        $this->assertTrue($nameField->searchable);
    }

    public function testFromModelSchemaThrowsForNonActiveRecord(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a subclass');

        PhiiExportSchema::fromModelSchema($this->stubModelSchema(), DataMapperEntity::class, 'admin');
    }

    public function testToArrayContainsAllKeys(): void
    {
        $export = PhiiExportSchema::fromModelSchema($this->stubModelSchema(), User::class, 'admin');

        $data = $export->toArray();

        $this->assertSame([
            'entity',
            'className',
            'label',
            'icon',
            'table',
            'module',
            'namespace',
            'fields',
            'permissions',
            'layout',
            'description',
        ], array_keys($data));
        $this->assertIsArray($data['fields']);
        $this->assertSame(['name', 'type', 'label', 'widget', 'column', 'required', 'searchable', 'filterable', 'sortable', 'options', 'rules', 'extra'], array_keys($data['fields'][1]));
    }

    public function testFromArrayReconstructsIdenticalExport(): void
    {
        $export = PhiiExportSchema::fromModelSchema($this->stubModelSchema(), User::class, 'admin');

        $rebuilt = PhiiExportSchema::fromArray($export->toArray());

        $this->assertSame($export->toArray(), $rebuilt->toArray());
        $this->assertSame($export->entity, $rebuilt->entity);
        $this->assertSame($export->fields[1]->name, $rebuilt->fields[1]->name);
        $this->assertSame($export->fields[1]->options, $rebuilt->fields[1]->options);
    }

    public function testFromArrayAppliesDefaults(): void
    {
        $data = [
            'entity' => 'X',
            'className' => User::class,
            'label' => 'X',
            'icon' => 'x',
            'table' => 'xs',
            'module' => 'admin',
            'namespace' => 'Phue\Tests\Schii\Stubs',
            'fields' => [],
        ];

        $export = PhiiExportSchema::fromArray($data);

        $this->assertSame('grid', $export->layout);
        $this->assertNull($export->description);
        $this->assertSame([], $export->permissions);
        $this->assertSame([], $export->fields);
    }

    public function testGetFormFieldNamesReturnsEveryField(): void
    {
        $export = PhiiExportSchema::fromModelSchema($this->stubModelSchema(), User::class);

        $this->assertSame(['id', 'name'], $export->getFormFieldNames());
    }

    public function testGetColumnFieldNamesReturnsOnlyColumnFlags(): void
    {
        $export = PhiiExportSchema::fromModelSchema($this->stubModelSchema(), User::class);

        $this->assertSame(['name'], $export->getColumnFieldNames());
    }
}