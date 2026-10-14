<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Schema;

use DateInterval;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\ActiveRecord\Relations\BelongsTo;
use Phue\Core\Database\ActiveRecord\Relations\HasMany;
use Phue\Core\Database\ActiveRecord\Relations\HasOne;
use Phue\Core\Database\ActiveRecord\Relations\Relation;
use Phue\Core\Database\Attributes\Field;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Config\SchiiConfig;
use Phue\Modules\Schii\Schema\FieldSchema;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Psr\SimpleCache\CacheInterface;

/**
 * A related model whose display column is declared searchable, which is the
 * strongest signal the resolver looks for.
 */
class User extends BaseActiveRecord
{
    // Deliberately named User: belongsTo() derives its default foreign key from
    // the related model's short name, so this stub is what proves that a plain
    // `belongsTo(User::class)` lands on a column called user_id.
    protected string $table = 'users';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'integer')]
    #[Column(label: 'A Numeric Noop', searchable: false)]
    public int $legacy_score = 0;

    #[Field(type: 'string', required: true)]
    #[Column(label: 'Username', searchable: true)]
    public string $username = '';

    #[Field(type: 'string')]
    #[Column(label: 'Email')]
    public string $email = '';
}

/**
 * Same shape, but nothing is searchable, so resolution must fall back to the
 * first textual non-primary-key column.
 */
class BlindUser extends BaseActiveRecord
{
    protected string $table = 'relation_blind_authors';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'integer')]
    public int $legacy_score = 0;

    #[Field(type: 'string')]
    public string $nickname = '';
}

/**
 * Only a primary key and a boolean: nothing readable can be derived, so the
 * resolver must report an empty label instead of inventing one.
 */
class OpaqueUser extends BaseActiveRecord
{
    protected string $table = 'relation_opaque_authors';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'boolean')]
    public bool $active = true;
}

/**
 * The subject of most tests: a plain integer foreign key plus a BelongsTo
 * method naming the related model.
 */
#[UI(label: 'Relation Posts', icon: 'postcard')]
class RelationPostStub extends BaseActiveRecord
{
    protected string $table = 'relation_posts';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    #[Column(sortable: true)]
    public int $id = 0;

    #[Field(type: 'string', required: true)]
    #[Column(searchable: true)]
    public string $title = '';

    #[Field(type: 'integer', required: true)]
    #[Column(label: 'User ID', filterable: true)]
    public int $user_id = 0;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

/**
 * A belongsTo whose foreign key does not follow the {model}_id convention.
 */
class RelationCustomKeyStub extends BaseActiveRecord
{
    protected string $table = 'relation_custom_keys';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'integer')]
    public int $writer_id = 0;

    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'writer_id');
    }
}

/**
 * The foreign key column is absent from the schema, so there is nothing to
 * annotate. Inferring must degrade quietly rather than invent a field.
 */
class RelationDanglingKeyStub extends BaseActiveRecord
{
    protected string $table = 'relation_dangling_keys';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

/**
 * hasMany/hasOne keep their foreign key on the other table, so neither may
 * annotate a local column.
 */
class RelationParentStub extends BaseActiveRecord
{
    protected string $table = 'relation_parents';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'string')]
    #[Column(searchable: true)]
    public string $name = '';

    #[Field(type: 'integer')]
    public int $user_id = 0;

    public function children(): HasMany
    {
        return $this->hasMany(RelationPostStub::class);
    }

    public function favourite(): HasOne
    {
        return $this->hasOne(RelationPostStub::class);
    }
}

/**
 * Two models that point at each other. Resolving the display label must not
 * re-enter parse() for the model it is already inside.
 */
class RelationCycleAStub extends BaseActiveRecord
{
    protected string $table = 'relation_cycle_a';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'string')]
    #[Column(searchable: true)]
    public string $label = '';

    #[Field(type: 'integer')]
    public int $b_id = 0;

    public function b(): BelongsTo
    {
        return $this->belongsTo(RelationCycleBStub::class, 'b_id');
    }
}

class RelationCycleBStub extends BaseActiveRecord
{
    protected string $table = 'relation_cycle_b';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'string')]
    #[Column(searchable: true)]
    public string $caption = '';

    #[Field(type: 'integer')]
    public int $a_id = 0;

    public function a(): BelongsTo
    {
        return $this->belongsTo(RelationCycleAStub::class, 'a_id');
    }
}

/**
 * Methods that look relation-adjacent but are not relations: static, parameterised,
 * throwing, or returning a non-relation. None may break parsing.
 */
class RelationNoiseStub extends BaseActiveRecord
{
    protected string $table = 'relation_noise';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'integer')]
    public int $user_id = 0;

    public static function user(): BelongsTo
    {
        return new User()->belongsTo(User::class);
    }

    public function userWithArgument(string $scope): BelongsTo
    {
        return $this->belongsTo(User::class, $scope);
    }

    public function userThatThrows(): BelongsTo
    {
        throw new \RuntimeException('relation exploded');
    }

    public function userThatLies(): BelongsTo
    {
        return 'not a relation at all';
    }

    public function notARelation(): string
    {
        return 'plain';
    }

    public function helper(): void
    {
    }
}

/**
 * A relation declared with the abstract base as its return type cannot say
 * which side owns the foreign key, so it must be left alone.
 */
class RelationAmbiguousStub extends BaseActiveRecord
{
    protected string $table = 'relation_ambiguous';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'integer')]
    public int $user_id = 0;

    public function user(): Relation
    {
        return $this->belongsTo(User::class);
    }
}

/**
 * The declared #[Field] type and widget of an annotated field must survive
 * untouched - relation metadata is additive, and the SQL layer still has to
 * see an int column named user_id.
 */
class RelationUntypedPostStub extends BaseActiveRecord
{
    protected string $table = 'relation_untyped_posts';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'integer', required: true, indexed: true, rules: ['required', 'integer'])]
    #[Column(label: 'User ID', searchable: true, filterable: true, sortable: true)]
    public int $user_id = 0;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

/**
 * An explicit static select on the same column. The author's literal options
 * must win over inference - a deliberate choice is not something to override.
 */
class RelationPresetOptionsStub extends BaseActiveRecord
{
    protected string $table = 'relation_preset_options';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'select')]
    #[Column(label: 'User ID', options: ['legacy-a', 'legacy-b'])]
    public string $user_id = '';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

final class ArraySimpleRelationCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->values[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->values = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }
        return $out;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}

#[Group('schii')]
final class RelationInferenceTest extends TestCase
{
    /**
     * Build a registry with relation inference switched on or off.
     */
    private function registry(bool $autoInfer, ?CacheInterface $cache = null): SchemaRegistry
    {
        return new SchemaRegistry(
            null,
            $cache,
            'rel_test_',
            'Phue\\Tests\\Schii\\Schema',
            SchiiConfig::fromArray(['relations' => ['autoInfer' => $autoInfer]]),
        );
    }

    public function testRelationIsNotInferredWhenAutoInferIsOff(): void
    {
        $schema = $this->registry(false)->get(RelationPostStub::class);

        self::assertNull(
            $schema->fields['user_id']->relation,
            'autoInfer=false must leave the schema exactly as it was before relations existed'
        );
    }

    public function testDefaultRegistryInfersNothing(): void
    {
        // A registry built without a config object is the default for every
        // generator command; it must not start inferring.
        $schema = (new SchemaRegistry())->get(RelationPostStub::class);

        self::assertNull($schema->fields['user_id']->relation);
    }

    public function testBelongsToAnnotatesTheForeignKeyField(): void
    {
        $relation = $this->registry(true)->get(RelationPostStub::class)->fields['user_id']->relation;

        self::assertIsArray($relation);
        self::assertSame(User::class, $relation['model']);
        self::assertSame('username', $relation['label']);
        self::assertSame('belongsTo', $relation['type']);
    }

    public function testSearchableColumnWinsAsTheDisplayLabel(): void
    {
        // User declares legacy_score before username, so a naive
        // "first column" rule would pick the integer.
        $relation = $this->registry(true)->get(RelationPostStub::class)->fields['user_id']->relation;

        self::assertSame('username', $relation['label']);
    }

    public function testFallsBackToFirstTextualColumnWhenNoneAreSearchable(): void
    {
        $fields = $this->fieldsOf(RelationBlindRelationPostStub::class);

        self::assertSame('nickname', $this->labelFor($fields));
    }

    public function testPrimaryKeyIsNeverChosenAsTheDisplayLabel(): void
    {
        $fields = $this->fieldsOf(RelationOpaqueRelationPostStub::class);

        self::assertSame('', $this->labelFor($fields));
    }

    public function testNonStandardForeignKeyNameIsResolved(): void
    {
        $relation = $this->registry(true)->get(RelationCustomKeyStub::class)->fields['writer_id']->relation;

        self::assertIsArray($relation);
        self::assertSame(User::class, $relation['model']);
    }

    public function testMissingForeignKeyColumnIsIgnoredQuietly(): void
    {
        $schema = $this->registry(true)->get(RelationDanglingKeyStub::class);

        self::assertArrayNotHasKey('owner_id', $schema->fields);
        self::assertArrayHasKey('id', $schema->fields);
    }

    public function testHasManyAndHasOneAnnotateNoLocalColumn(): void
    {
        $schema = $this->registry(true)->get(RelationParentStub::class);

        // user_id exists on this model but is owned by no relation here.
        self::assertNull($schema->fields['user_id']->relation);
    }

    public function testMutuallyReferencingModelsDoNotRecurseForever(): void
    {
        $a = $this->registry(true)->get(RelationCycleAStub::class);

        self::assertSame(RelationCycleBStub::class, $a->fields['b_id']->relation['model']);
        self::assertSame('caption', $a->fields['b_id']->relation['label']);
    }

    public function testNonRelationMethodsAreSkipped(): void
    {
        $schema = $this->registry(true)->get(RelationNoiseStub::class);

        self::assertNull(
            $schema->fields['user_id']->relation,
            'static, parameterised, throwing and lying methods must all leave the field alone'
        );
    }

    public function testRelationDeclaredWithTheAbstractBaseIsIgnored(): void
    {
        $schema = $this->registry(true)->get(RelationAmbiguousStub::class);

        self::assertNull($schema->fields['user_id']->relation);
    }

    public function testAnnotatedFieldKeepsItsTypeWidgetRulesAndLabels(): void
    {
        $before = $this->registry(false)->get(RelationUntypedPostStub::class)->fields['user_id'];
        $after = $this->registry(true)->get(RelationUntypedPostStub::class)->fields['user_id'];

        self::assertSame($before->type, $after->type);
        self::assertSame($before->widget, $after->widget);
        self::assertSame($before->label, $after->label);
        self::assertSame($before->rules, $after->rules);
        self::assertSame($before->required, $after->required);
        self::assertSame($before->indexed, $after->indexed);
        self::assertSame($before->searchable, $after->searchable);
        self::assertSame($before->filterable, $after->filterable);
        self::assertSame($before->sortable, $after->sortable);
        self::assertSame($before->column, $after->column);
    }

    public function testAuthorSuppliedOptionsSurviveInference(): void
    {
        $field = $this->registry(true)->get(RelationPresetOptionsStub::class)->fields['user_id'];

        self::assertSame(['legacy-a', 'legacy-b'], $field->options);
    }

    public function testRelationSurvivesThePersistentCacheRoundTrip(): void
    {
        $cache = new ArraySimpleRelationCache();

        $warm = $this->registry(true, $cache)->get(RelationPostStub::class);
        self::assertIsArray($warm->fields['user_id']->relation);

        // A second registry over the same cache must serve the identical schema.
        $cold = $this->registry(true, $cache)->get(RelationPostStub::class);
        self::assertSame(
            $warm->fields['user_id']->relation,
            $cold->fields['user_id']->relation,
            'relation metadata is dropped by the cache unless FieldSchema serialises it'
        );
    }

    public function testFieldSchemaSerialisationIsLosslessForRelations(): void
    {
        $field = $this->registry(true)->get(RelationPostStub::class)->fields['user_id'];

        $restored = FieldSchema::fromArray($field->toArray());

        self::assertEquals($field, $restored);
    }

    public function testFieldSchemaFromArrayWithoutRelationKeyYieldsNull(): void
    {
        $field = $this->registry(false)->get(RelationPostStub::class)->fields['user_id'];

        $payload = $field->toArray();
        unset($payload['relation']);

        self::assertNull(FieldSchema::fromArray($payload)->relation);
    }

    public function testRelationAccessorsExposeTheDefinition(): void
    {
        $relation = (new RelationPostStub())->user();

        self::assertInstanceOf(BelongsTo::class, $relation);
        self::assertSame(User::class, $relation->getRelatedClass());
        self::assertSame('user_id', $relation->getForeignKey());
        self::assertSame('id', $relation->getLocalKey());
    }

    /**
     * Parse a model with inference on and return its field map.
     *
     * @param class-string $modelClass
     * @return array<string, FieldSchema>
     */
    private function fieldsOf(string $modelClass): array
    {
        return $this->registry(true)->get($modelClass)->fields;
    }

    /**
     * @param array<string, FieldSchema> $fields
     */
    private function labelFor(array $fields): string
    {
        $relation = $fields['user_id']->relation;
        self::assertIsArray($relation);

        return $relation['label'];
    }
}

class RelationBlindRelationPostStub extends BaseActiveRecord
{
    protected string $table = 'relation_blind_posts';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'integer')]
    public int $user_id = 0;

    public function user(): BelongsTo
    {
        // Explicit foreign key: the class name is BlindUser, so the derived
        // default would be blindsightblinduser_id rather than user_id.
        return $this->belongsTo(BlindUser::class, 'user_id');
    }
}

class RelationOpaqueRelationPostStub extends BaseActiveRecord
{
    protected string $table = 'relation_opaque_posts';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    public int $id = 0;

    #[Field(type: 'integer')]
    public int $user_id = 0;

    public function user(): BelongsTo
    {
        return $this->belongsTo(OpaqueUser::class, 'user_id');
    }
}
