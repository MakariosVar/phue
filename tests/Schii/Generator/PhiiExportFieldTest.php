<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Generator;

use PHPUnit\Framework\TestCase;
use Phue\Modules\Schii\Generator\PhiiExportField;
use Phue\Modules\Schii\Schema\FieldSchema;

/**
 * PhiiExportField: field mapping from FieldSchema, array round-trip fidelity
 * and default/boolean hardening.
 *
 * @package Phue\Tests\Schii\Generator
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhiiExportFieldTest extends TestCase
{
    public function testFromFieldSchemaMapsEveryProperty(): void
    {
        $field = new FieldSchema(
            name: 'price',
            type: 'number',
            label: 'Price',
            widget: 'input',
            column: true,
            required: true,
            searchable: false,
            filterable: true,
            sortable: false,
            options: ['step' => '0.01'],
            rules: ['numeric', 'min:0'],
        );

        $export = PhiiExportField::fromFieldSchema($field);

        $this->assertSame('price', $export->name);
        $this->assertSame('number', $export->type);
        $this->assertSame('Price', $export->label);
        $this->assertSame('input', $export->widget);
        $this->assertTrue($export->column);
        $this->assertTrue($export->required);
        $this->assertFalse($export->searchable);
        $this->assertTrue($export->filterable);
        $this->assertFalse($export->sortable);
        $this->assertSame(['step' => '0.01'], $export->options);
        $this->assertSame(['numeric', 'min:0'], $export->rules);
    }

    public function testFromFieldSchemaExtraDefaultsToEmptyArray(): void
    {
        $field = new FieldSchema(
            name: 'name',
            type: 'text',
            label: 'Name',
            widget: 'input',
            column: true,
            required: false,
            searchable: false,
            filterable: false,
            sortable: false,
        );

        $export = PhiiExportField::fromFieldSchema($field);

        $this->assertSame([], $export->extra);
    }

    public function testToArrayContainsAllSerializableKeys(): void
    {
        $export = new PhiiExportField(
            name: 'active',
            type: 'boolean',
            label: 'Active',
            widget: 'checkbox',
            column: true,
            required: false,
            searchable: false,
            filterable: true,
            sortable: true,
            options: [],
            rules: ['boolean'],
            extra: ['help' => 'Is this record active?'],
        );

        $data = $export->toArray();

        $this->assertSame([
            'name' => 'active',
            'type' => 'boolean',
            'label' => 'Active',
            'widget' => 'checkbox',
            'column' => true,
            'required' => false,
            'searchable' => false,
            'filterable' => true,
            'sortable' => true,
            'options' => [],
            'rules' => ['boolean'],
            'extra' => ['help' => 'Is this record active?'],
        ], $data);
    }

    public function testFromArrayReconstructsIdenticalInstance(): void
    {
        $export = new PhiiExportField(
            name: 'email',
            type: 'email',
            label: 'Email',
            widget: 'input',
            column: true,
            required: true,
            searchable: true,
            filterable: false,
            sortable: false,
            options: ['placeholder' => 'a@b.c'],
            rules: ['email'],
            extra: [],
        );

        $rebuilt = PhiiExportField::fromArray($export->toArray());

        $this->assertSame($export->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayHardenStringsToBooleans(): void
    {
        $data = [
            'name' => 'flag',
            'type' => 'text',
            'label' => 'Flag',
            'widget' => 'input',
            'column' => 1,
            'required' => 'yes',
            'searchable' => '',
            'filterable' => 0,
            'sortable' => '1',
        ];

        $export = PhiiExportField::fromArray($data);

        $this->assertTrue($export->column);
        $this->assertTrue($export->required);
        $this->assertFalse($export->searchable);
        $this->assertFalse($export->filterable);
        $this->assertTrue($export->sortable);
        $this->assertSame([], $export->options);
        $this->assertSame([], $export->rules);
        $this->assertSame([], $export->extra);
    }

    public function testFromArrayRequiresStringNameAndType(): void
    {
        $this->expectException(\TypeError::class);

        @PhiiExportField::fromArray(['label' => 'Bare']);
    }
}