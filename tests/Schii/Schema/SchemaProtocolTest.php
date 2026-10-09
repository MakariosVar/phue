<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Schema;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Schema\FieldSchema;
use Phue\Modules\Schii\Schema\ModelSchema;
use Phue\Modules\Schii\Schema\SchemaRegistry;

#[UI(label: 'Protocol Products', icon: 'box', layout: 'grid', description: 'Product catalog schema')]
class ProtocolProductStub extends BaseActiveRecord
{
    protected string $table = 'protocol_products';

    #[Field(type: 'int', primaryKey: true, writable: false)]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', required: true)]
    #[Column(label: 'Title', searchable: true, sortable: true)]
    public string $title = '';

    #[Field(type: 'email')]
    #[Column(label: 'Support Email')]
    public string $support_email = '';

    #[Field(type: 'select')]
    #[Column(label: 'Category', options: ['electronics', 'books'])]
    public string $category = '';

    #[Field(type: 'date')]
    #[Column(label: 'Release Date')]
    public string $release_date = '';

    #[Field(type: 'email')]
    #[Column(label: 'Custom Widget Email', widget: 'custom_input')]
    public string $custom_widget_field = '';

    #[Field(type: 'text', readonly: true)]
    #[Column(label: 'Readonly Code')]
    public string $internal_code = 'FIXED';
}

#[Group('unit')]
final class SchemaProtocolTest extends TestCase
{
    public function testFieldSchemaSerializationRoundTrip(): void
    {
        $schema = new FieldSchema(
            name: 'custom_code',
            type: 'text',
            label: 'Custom Code',
            widget: 'input',
            column: true,
            required: true,
            searchable: true,
            filterable: false,
            sortable: true,
            options: ['prefix' => 'SKU'],
            rules: ['required', 'min:3'],
            writable: false,
            readonly: true,
            primaryKey: false,
        );

        $array = $schema->toArray();

        $this->assertFalse($array['writable']);
        $this->assertTrue($array['readonly']);
        $this->assertFalse($array['primaryKey']);

        $rehydrated = FieldSchema::fromArray($array);

        $this->assertSame($schema->name, $rehydrated->name);
        $this->assertSame($schema->type, $rehydrated->type);
        $this->assertSame($schema->label, $rehydrated->label);
        $this->assertSame($schema->widget, $rehydrated->widget);
        $this->assertSame($schema->column, $rehydrated->column);
        $this->assertSame($schema->required, $rehydrated->required);
        $this->assertSame($schema->searchable, $rehydrated->searchable);
        $this->assertSame($schema->filterable, $rehydrated->filterable);
        $this->assertSame($schema->sortable, $rehydrated->sortable);
        $this->assertSame($schema->options, $rehydrated->options);
        $this->assertSame($schema->rules, $rehydrated->rules);
        $this->assertSame($schema->writable, $rehydrated->writable);
        $this->assertSame($schema->readonly, $rehydrated->readonly);
        $this->assertSame($schema->primaryKey, $rehydrated->primaryKey);
    }

    public function testFieldSchemaFromArrayProvidesSafeDefaults(): void
    {
        $minimal = FieldSchema::fromArray([
            'name' => 'title',
        ]);

        $this->assertSame('title', $minimal->name);
        $this->assertSame('string', $minimal->type);
        $this->assertTrue($minimal->writable, 'Default writable should be true');
        $this->assertFalse($minimal->readonly, 'Default readonly should be false');
        $this->assertFalse($minimal->primaryKey, 'Default primaryKey should be false');
    }

    public function testModelSchemaFromArrayProvidesDefaultIconAndLayout(): void
    {
        $data = [
            'entity' => 'Item',
            'className' => 'App\\Models\\Item',
            'label' => 'Item Label',
            'description' => 'Test description',
            'fields' => [],
            'permissions' => ['read' => 'admin.item.read'],
        ];

        $modelSchema = ModelSchema::fromArray($data);

        $this->assertSame('table', $modelSchema->icon);
        $this->assertSame('grid', $modelSchema->layout);
        $this->assertSame('Test description', $modelSchema->description);

        $serialized = $modelSchema->toArray();
        $this->assertSame('table', $serialized['icon']);
        $this->assertSame('grid', $serialized['layout']);
        $this->assertSame('Test description', $serialized['description']);
    }

    public function testRegistryParsesFieldExtensionsAndInfersSmartWidgets(): void
    {
        $registry = new SchemaRegistry();
        $schema = $registry->get(ProtocolProductStub::class);

        $this->assertInstanceOf(ModelSchema::class, $schema);
        $this->assertSame('box', $schema->icon);
        $this->assertSame('grid', $schema->layout);
        $this->assertSame('Product catalog schema', $schema->description);

        // ID field
        $idField = $schema->fields['id'];
        $this->assertTrue($idField->primaryKey);
        $this->assertFalse($idField->writable);
        $this->assertFalse($idField->readonly);

        // Title field
        $titleField = $schema->fields['title'];
        $this->assertSame('text', $titleField->type);
        $this->assertSame('input', $titleField->widget);
        $this->assertTrue($titleField->writable);
        $this->assertFalse($titleField->readonly);
        $this->assertFalse($titleField->primaryKey);

        // Support email: type preserved, smart widget assigned
        $emailField = $schema->fields['support_email'];
        $this->assertSame('email', $emailField->type, 'Type email must not be flattened to text');
        $this->assertSame('email', $emailField->widget, 'Widget must be smartly inferred as email');

        // Category select: type preserved, smart widget assigned
        $selectField = $schema->fields['category'];
        $this->assertSame('select', $selectField->type, 'Type select must not be flattened to text');
        $this->assertSame('select', $selectField->widget, 'Widget must be smartly inferred as select');

        // Release date: type preserved, smart widget assigned
        $dateField = $schema->fields['release_date'];
        $this->assertSame('date', $dateField->type, 'Type date must be preserved');
        $this->assertSame('date', $dateField->widget, 'Widget must be smartly inferred as date');

        // Explicit widget overrides smart inference
        $customWidgetField = $schema->fields['custom_widget_field'];
        $this->assertSame('email', $customWidgetField->type);
        $this->assertSame('custom_input', $customWidgetField->widget, 'Explicit widget must be preserved');

        // Readonly field
        $readonlyField = $schema->fields['internal_code'];
        $this->assertTrue($readonlyField->readonly);
    }
}
