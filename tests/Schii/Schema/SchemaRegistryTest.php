<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Schema;

use PHPUnit\Framework\TestCase;
use Phue\Modules\Schii\Attribute\Field;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Schema\SchemaRegistry;

/**
 * SchemaRegistry parsing of index/length and type-normalization flags.
 *
 * @package Phue\Tests\Schii\Schema
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class SchemaRegistryTest extends TestCase
{
    public function testFieldIndexedFlagParses(): void
    {
        $schema = (new SchemaRegistry())->get(IndexedUser::class);

        $this->assertTrue($schema->fields['email']->indexed);
        $this->assertFalse($schema->fields['name']->indexed);
    }

    public function testBoolTypeMapsToBoolean(): void
    {
        $schema = (new SchemaRegistry())->get(TypedRecord::class);

        $this->assertSame('boolean', $schema->fields['active']->type);
        $this->assertSame('int', $schema->fields['score']->type);
        $this->assertSame('float', $schema->fields['ratio']->type);
    }
}

#[UI(label: 'Indexed User')]
class IndexedUser extends \Phue\Core\Database\ActiveRecord\BaseActiveRecord
{
    protected string $table = 'indexed_users';

    #[Field(type: 'integer', label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'text', required: true)]
    public string $name = '';

    #[Field(type: 'text', indexed: true)]
    public string $email = '';
}

#[UI(label: 'Typed Record')]
class TypedRecord extends \Phue\Core\Database\ActiveRecord\BaseActiveRecord
{
    protected string $table = 'typed_records';

    #[Field(type: 'integer', label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'bool')]
    public bool $active = false;

    #[Field(type: 'int')]
    public int $score = 0;

    #[Field(type: 'float')]
    public float $ratio = 0.0;
}