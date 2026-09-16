<?php

declare(strict_types=1);

namespace Phue\Tests\Schii;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phue\Modules\Schii\Generator\PhiiExportField;
use Phue\Modules\Schii\Generator\PhiiExportSchema;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Schii\Stubs\DataMapperEntity;
use Phue\Tests\Schii\Stubs\Profile;
use Phue\Tests\Schii\Stubs\User;

/**
 * SchemaRegistry discovery + export-to-Phii conversion layer.
 *
 * @package Phue\Tests\Schii
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class SchemaRegistryExportTest extends TestCase
{
    private const STUB_NAMESPACE = 'Phue\Tests\Schii\Stubs';

    public function testDiscoverModelClassesFindsActiveRecordsOnly(): void
    {
        $registry = new SchemaRegistry();

        $classes = $registry->discoverModelClasses(self::STUB_NAMESPACE);

        $this->assertContains(User::class, $classes);
        $this->assertContains(Profile::class, $classes);
        $this->assertNotContains(DataMapperEntity::class, $classes);
    }

    public function testDiscoverModelClassesReturnsEmptyForMissingNamespace(): void
    {
        $registry = new SchemaRegistry();

        $this->assertSame([], $registry->discoverModelClasses('Phue\Tests\Schii\DoesNotExist'));
    }

    public function testDiscoverEntitiesReturnsKebabSlugs(): void
    {
        $registry = new SchemaRegistry();

        $entities = $registry->discoverEntities(self::STUB_NAMESPACE);

        $this->assertContains('user', $entities);
        $this->assertContains('profile', $entities);
    }

    public function testToPhiiExportSchemaConvertsAnActiveRecord(): void
    {
        $registry = new SchemaRegistry();

        $export = $registry->toPhiiExportSchema(User::class, 'admin');

        $this->assertInstanceOf(PhiiExportSchema::class, $export);
        $this->assertSame('User', $export->entity);
        $this->assertSame(User::class, $export->className);
        $this->assertSame('users', $export->table);
        $this->assertSame('admin', $export->module);
        $this->assertSame('Phue\Tests\Schii\Stubs', $export->namespace);
        $this->assertContainsOnlyInstancesOf(PhiiExportField::class, $export->fields);
    }

    public function testToPhiiExportSchemaThrowsForNonActiveRecord(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a subclass');

        (new SchemaRegistry())->toPhiiExportSchema(DataMapperEntity::class, 'admin');
    }

    public function testToPhiiExportSchemaCarriesUiMetadata(): void
    {
        $registry = new SchemaRegistry();

        $export = $registry->toPhiiExportSchema(Profile::class, 'admin');

        $this->assertSame('card', $export->icon);
        $this->assertSame('cards', $export->layout);
        $this->assertSame('User profile cards', $export->description);
        $this->assertSame('Profiles', $export->label);
    }

    public function testGetAllPhiiExportSchemasKeyedByClass(): void
    {
        $registry = new SchemaRegistry();

        $schemas = $registry->getAllPhiiExportSchemas(self::STUB_NAMESPACE, 'admin');

        $this->assertArrayHasKey(User::class, $schemas);
        $this->assertArrayHasKey(Profile::class, $schemas);
        $this->assertCount(2, $schemas);
        $this->assertSame('admin', $schemas[User::class]->module);
    }

    public function testExportFieldsRespectSchemaAttributeFlags(): void
    {
        $registry = new SchemaRegistry();

        $export = $registry->toPhiiExportSchema(Profile::class, 'admin');

        $byName = [];
        foreach ($export->fields as $field) {
            $byName[$field->name] = $field;
        }

        $this->assertTrue($byName['full_name']->required);
        $this->assertTrue($byName['full_name']->searchable);
        $this->assertTrue($byName['email']->searchable);
        $this->assertTrue($byName['active']->filterable);
        $this->assertSame('checkbox', $byName['active']->widget);
    }
}