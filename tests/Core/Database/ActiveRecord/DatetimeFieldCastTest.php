<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database\ActiveRecord;

use DateTimeImmutable;
use DateTimeInterface;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use Phue\Tests\Support\SqliteFactory;
use InvalidArgumentException;
use Throwable;

/**
 * #[Field(type: 'datetime')] and the type of the property it lands on.
 *
 * The defect this guards
 * ----------------------
 * FieldAttributeCastingTest pins that #[Field] applies the cast it declares. It
 * has no datetime case, and datetime is the one cast in the map that produces
 * something other than a scalar:
 *
 *     'int', 'integer'  => (int) $value
 *     'float'           => (float) $value
 *     'bool'            => (bool) $value
 *     'datetime'        => new \DateTimeImmutable($value)   <-- an object
 *
 * Scalar casts survive a mismatched property because PHP coerces int-to-string
 * on assignment, so a wrong pairing there is quiet. An object does not coerce.
 * It TypeErrors, from inside ReflectionProperty::setValue, at the moment the row
 * is hydrated:
 *
 *     Cannot assign DateTimeImmutable to property App\Models\Comment::$created_at of type string
 *
 * That is the shape of the report this file exists for: a 500 on
 * POST /api/schii/comment with no mention of the model, the property, the cast
 * or the declaration that caused it, because the failure surfaces one layer below
 * the thing that is actually wrong.
 *
 * Why the error must name the model
 * ---------------------------------
 * The framework already refuses one of its two impossible declarations up front.
 * SchemaRegistry::parse() does:
 *
 *     RuleEngine::assertKnownRules($rules, $modelClass . '::$' . $propertyName);
 *
 * on the reasoning that an unknown rule should be attributed to the model that
 * declared it rather than skipped. An un-holdable cast is the same kind of error
 * — a declaration that cannot mean what it says — and it gets no such check.
 * These tests therefore do not assert that hydration succeeds; they assert that
 * a model whose datetime cast cannot land on its property is REFUSED BY NAME,
 * at the point where the model is read, and never as a raw TypeError from the
 * reflection layer.
 *
 * What is deliberately NOT pinned here
 * ------------------------------------
 * Whether a string property may hold a datetime column is a design decision
 * (coerce to 'Y-m-d H:i:s', or refuse the declaration). What is pinned is the
 * part that holds either way: the framework must not answer it with a
 * ReflectionException-shaped TypeError that names nothing.
 */
class TimestampedWidget extends BaseActiveRecord
{
    protected string $table = 'timestamped_widgets';

    #[Field(type: 'datetime')]
    public ?DateTimeImmutable $happened_at = null;

    #[Field(type: 'datetime')]
    public ?DateTimeImmutable $optional_at = null;
}

/**
 * The reported declaration: a datetime column held in a string property.
 */
class StringDatedWidget extends BaseActiveRecord
{
    protected string $table = 'string_dated_widgets';

    #[Field(type: 'integer')]
    public int $quantity = 0;

    #[Field(type: 'datetime')]
    public ?string $happened_at = null;
}

/**
 * The declared override. getAutoCast() consults $casts BEFORE the attribute, so
 * a model author is already allowed to say "this column is a DATETIME, this
 * property is a string". Whether that override also reaches the write path is
 * the question the last two tests ask.
 */
class OverriddenCastWidget extends BaseActiveRecord
{
    protected string $table = 'overridden_cast_widgets';

    protected array $casts = [
        'happened_at' => 'string',
    ];

    #[Field(type: 'datetime')]
    public ?string $happened_at = null;
}

/**
 * The shape docs/Database.md documents for $casts: casts declared, no #[Field]
 * anywhere. getAutoCast() reads these on the way in; getDatabaseAttributes()
 * did not, so they never reached the column.
 */
class DeclaredCastBag extends BaseActiveRecord
{
    protected string $table = 'declared_cast_bags';

    protected array $casts = [
        'items' => 'json',
        'paid' => 'bool',
        'total' => 'float',
    ];

    public array $items = [];

    public bool $paid = false;

    public float $total = 0.0;
}

/**
 * The negative control: no $casts, no #[Field].
 */
class UncastBag extends BaseActiveRecord
{
    protected string $table = 'uncast_bags';

    public ?string $payload = null;
}

/**
 * The same disease, different cast. json is the other cast in the map that
 * builds a value rather than coercing one, so it produced the identical fatal:
 *
 *     TypeError: Cannot assign array to property ... of type string
 */
class JsonIntoStringWidget extends BaseActiveRecord
{
    protected string $table = 'json_into_string_widgets';

    #[Field(type: 'json')]
    public ?string $payload = null;
}

/**
 * A union that already contains the produced class is holdable, and refusing it
 * would be refusing a correct declaration.
 */
class UnionDatedWidget extends BaseActiveRecord
{
    protected string $table = 'union_dated_widgets';

    #[Field(type: 'datetime')]
    public DateTimeImmutable|string|null $happened_at = null;
}

/**
 * 'object' is a blanket, not a class: any DateTimeImmutable satisfies it.
 */
class ObjectDatedWidget extends BaseActiveRecord
{
    protected string $table = 'object_dated_widgets';

    #[Field(type: 'datetime')]
    public ?object $happened_at = null;
}

/**
 * 'mixed' is the other blanket. Refusing it would make #[Field(type: 'datetime')]
 * unusable on any dynamic model.
 */
class MixedDatedWidget extends BaseActiveRecord
{
    protected string $table = 'mixed_dated_widgets';

    #[Field(type: 'datetime')]
    public mixed $happened_at = null;
}

/**
 * The interface form, which is what a model that wants to accept either a
 * DateTime or a DateTimeImmutable has to write.
 */
class InterfaceDatedWidget extends BaseActiveRecord
{
    protected string $table = 'interface_dated_widgets';

    #[Field(type: 'datetime')]
    public ?DateTimeInterface $happened_at = null;
}

/**
 * An untyped property accepts whatever setValue() is given, so there is nothing
 * to refuse.
 */
class UntypedDatedWidget extends BaseActiveRecord
{
    protected string $table = 'untyped_dated_widgets';

    #[Field(type: 'datetime')]
    public $happened_at = null;
}

#[Group('integration')]
final class DatetimeFieldCastTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Owned in-memory SQLite, never the ambient connection: this file issues
        // DDL and a test elsewhere that boots the real application replaces the
        // global connection with MariaDB process-wide. Same defence as
        // FieldAttributeCastingTest.
        $this->db = SqliteFactory::memory();
        $db = $this->db;
        BaseActiveRecord::setConnection($db);
        BaseActiveRecord::setConnectionResolver(static fn(): Connection => $db);

        $this->db->execute('DROP TABLE IF EXISTS timestamped_widgets');
        $this->db->execute('
            CREATE TABLE timestamped_widgets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                happened_at TEXT NULL,
                optional_at TEXT NULL
            );
        ');

        $this->db->execute('DROP TABLE IF EXISTS string_dated_widgets');
        $this->db->execute('
            CREATE TABLE string_dated_widgets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                quantity TEXT,
                happened_at TEXT NULL
            );
        ');

        $this->db->execute('DROP TABLE IF EXISTS overridden_cast_widgets');
        $this->db->execute('
            CREATE TABLE overridden_cast_widgets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                happened_at TEXT NULL
            );
        ');

        $this->db->execute('DROP TABLE IF EXISTS declared_cast_bags');
        $this->db->execute('
            CREATE TABLE declared_cast_bags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                items TEXT NULL,
                paid TEXT NULL,
                total TEXT NULL
            );
        ');

        $this->db->execute('DROP TABLE IF EXISTS uncast_bags');
        $this->db->execute('
            CREATE TABLE uncast_bags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payload TEXT NULL
            );
        ');
    }

    /**
     * The declared cast is honoured end to end when the property can hold it.
     *
     * This is the positive case the negative ones need: without it, a fix that
     * simply stopped casting datetimes would pass every test below.
     */
    public function testDatetimeFieldRoundTripsAsDateTimeImmutable(): void
    {
        $when = new DateTimeImmutable('2026-03-04 05:06:07');
        $widget = new TimestampedWidget();
        $widget->fill(['happened_at' => $when, 'optional_at' => null]);
        $this->assertTrue($widget->save());

        $stored = $this->db->query('SELECT happened_at FROM timestamped_widgets')->fetch();
        $this->assertSame(
            '2026-03-04 05:06:07',
            (string) $stored['happened_at'],
            'The column must receive a SQL datetime literal. An object here means reverseCast did not run on the write path.',
        );

        $reloaded = TimestampedWidget::find((int) $widget->getAttribute('id'));
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(
            DateTimeImmutable::class,
            $reloaded->happened_at,
            '#[Field(type: \'datetime\')] promises a DateTimeImmutable on the declared property. A string here means the cast silently stopped.',
        );
        $this->assertSame(
            '2026-03-04 05:06:07',
            $reloaded->happened_at->format('Y-m-d H:i:s'),
        );
    }

    /**
     * A SQL NULL datetime is a null property, not an uninitialised one.
     *
     * hydrateDeclaredProperty() skips a null for a non-nullable property rather
     * than assigning it, which leaves the typed property uninitialised. Reading
     * it then raises "must not be accessed before initialization" — an error
     * that points at the read, hours away from the nullable column that caused
     * it. A nullable property must not be left in that state.
     */
    public function testNullDatetimeColumnLeavesANullablePropertyNull(): void
    {
        $this->db->execute(
            'INSERT INTO timestamped_widgets (happened_at, optional_at) VALUES (NULL, NULL)'
        );

        $reloaded = TimestampedWidget::find(1);
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->optional_at);
    }

    /**
     * A string property cannot hold the object the datetime cast produces.
     *
     * The requirement is not "this must work" — a string property and a datetime
     * cast genuinely disagree. The requirement is that the disagreement is
     * REPORTED, by name, instead of surfacing as
     *
     *     TypeError: Cannot assign DateTimeImmutable to property
     *     ...\StringDatedWidget::$happened_at of type string
     *
     * from one layer below the declaration that caused it.
     *
     * It is an InvalidArgumentException rather than any new exception type
     * because that is what RuleEngine::assertKnownRules() already throws for the
     * sibling mistake — an unknown rule on the same property, refused at the
     * same kind of point. A second family would mean the author has to learn two
     * ways of being told the same thing.
     */
    public function testStringPropertyForADatetimeFieldIsRefusedByName(): void
    {
        $this->db->execute(
            "INSERT INTO string_dated_widgets (quantity, happened_at) VALUES ('1', '2026-03-04 05:06:07')"
        );

        try {
            StringDatedWidget::find(1);
            $this->fail('A datetime cast onto a string property must be refused, not silently coerced.');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(
                \TypeError::class,
                $e,
                'A reflection-layer TypeError is not a diagnosis: it names no cast, no attribute and no fix.',
            );
            $this->assertInstanceOf(InvalidArgumentException::class, $e);

            $message = $e->getMessage();

            $this->assertStringContainsString(
                StringDatedWidget::class,
                $message,
                'The error must name the model that declared it, or the author has three files to grep.',
            );
            $this->assertStringContainsString(
                'happened_at',
                $message,
                'The error must name the property.',
            );
            $this->assertStringContainsString(
                'datetime',
                $message,
                'The error must name the cast that cannot land, not just the types.',
            );
            $this->assertStringContainsString(
                'DateTimeImmutable',
                $message,
                'The error must say what the cast produces.',
            );
            $this->assertStringContainsString(
                '?string',
                $message,
                'The error must say what the property actually declares.',
            );
            $this->assertStringContainsString(
                '$casts',
                $message,
                'The error must name one of the two ways out. A refusal without a remedy is a dead end.',
            );
        }
    }

    /**
     * The refusal must land BEFORE a row is touched, not halfway through one.
     *
     * A model with a broken declaration that is never read is not broken in
     * anger; it is broken the first time somebody lists it. That is why the
     * assertion lives in the cached declaration walk rather than in the cast
     * itself — and it is observable here: the row exists, and nothing about it
     * ever reached the driver.
     */
    public function testTheRefusalHappensOnTheDeclarationNotOnTheRow(): void
    {
        $widget = new StringDatedWidget();

        try {
            $widget->fill(['quantity' => 1, 'happened_at' => '2026-03-04 05:06:07']);
            $widget->save();
            $this->fail('save() must not write a row for a model whose declaration cannot be honoured.');
        } catch (InvalidArgumentException) {
            $this->assertSame(
                0,
                (int) $this->db->query('SELECT COUNT(*) AS c FROM string_dated_widgets WHERE quantity = 1')->fetch()['c'],
                'The model was refused, so nothing may have been inserted.',
            );
        }
    }

    /**
     * json is the same defect with a different cast.
     *
     * It was in the same match expression in ModelGenerator and produced the
     * same fatal with an array instead of a DateTimeImmutable, so a fix that
     * only names datetime leaves half the disease in place.
     */
    public function testStringPropertyForAJsonFieldIsRefusedByName(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS json_into_string_widgets');
        $this->db->execute('
            CREATE TABLE json_into_string_widgets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payload TEXT NULL
            );
        ');
        $this->db->execute(
            "INSERT INTO json_into_string_widgets (payload) VALUES ('[\"a\"]')"
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(JsonIntoStringWidget::class);

        JsonIntoStringWidget::find(1);
    }

    /**
     * A $casts override is the documented way out, so it must actually be one.
     *
     * If the refusal ignored $casts then the answer the error gives would be a
     * lie: it would tell an author to write $casts and then refuse them anyway
     * on the next request. This is the test that keeps the remedy and the rule
     * in agreement — including on validate(), which reads the declaration walk
     * directly and never touches the instance.
     */
    public function testTheDeclaredOverrideIsTheRemedyTheErrorAdvertises(): void
    {
        $widget = new OverriddenCastWidget();
        $widget->fill(['happened_at' => '2026-03-04 05:06:07']);

        $this->assertTrue(
            $widget->validate(),
            'A $casts entry that resolves the pairing must survive validate(), which reads the same declaration walk that refuses the unresolvable one.',
        );
    }

    /**
     * Every declaration that CAN hold the produced value must be left alone.
     *
     * One test, five shapes, because the refusal is worthless if it is stricter
     * than the truth: an author who has written a correct model must never meet
     * it. Each is the exact edge of the check, not a sample of it.
     */
    public function testEveryDeclarationThatCanHoldTheCastIsAccepted(): void
    {
        $accepted = [
            UnionDatedWidget::class,
            ObjectDatedWidget::class,
            MixedDatedWidget::class,
            InterfaceDatedWidget::class,
            UntypedDatedWidget::class,
            TimestampedWidget::class,
        ];

        foreach ($accepted as $class) {
            $reflection = new \ReflectionClass($class);
            $property = $reflection->getProperty('happened_at');

            // Reaches the same assertion getFieldMeta() makes, through the same
            // public entry point hydration uses.
            $instance = $reflection->newInstance();
            $instance->setAttribute('happened_at', '2026-03-04 05:06:07');

            $this->assertTrue(
                property_exists($instance, 'happened_at'),
                $class . ' must survive hydration: ' . $property->getType(),
            );
        }
    }

    /**
     * The declared override is honoured on the way IN.
     *
     * $casts is documented as the way to declare a cast explicitly
     * (docs/Database.md, "Attribute Casting"), and getAutoCast() checks it
     * before the attribute. So a model author who writes
     *
     *     #[Field(type: 'datetime')]     // the COLUMN is a DATETIME
     *     protected array $casts = ['happened_at' => 'string'];   // the PROPERTY is text
     *
     * has said something coherent and Core already has the vocabulary for it.
     */
    public function testDeclaredCastOverrideIsHonouredOnHydration(): void
    {
        $widget = new OverriddenCastWidget();
        $widget->fill(['happened_at' => '2026-03-04 05:06:07']);

        $this->assertIsString(
            $widget->happened_at,
            '$casts sits in getAutoCast() ahead of the attribute, so it must win on hydration. An object here means the override was skipped.',
        );
    }

    /**
     * ...and on the way OUT, which today it is not.
     *
     * getDatabaseAttributes() calls static::getAutoCasts() directly and never
     * consults $casts, so the write path reverse-casts with the ATTRIBUTE map
     * while hydration applied the OVERRIDE. Two answers to one question, in the
     * same request.
     *
     * The datetime override cannot show it — reverseCast's datetime branch
     * passes a string through untouched — so this case uses the shape
     * docs/Database.md actually documents: a model that declares casts and
     * nothing else.
     */
    public function testDeclaredCastsAreReverseCastOnWriteWithoutAnyFieldAttribute(): void
    {
        $bag = new DeclaredCastBag();
        $bag->fill(['items' => ['a', 'b'], 'paid' => true, 'total' => 12.5]);
        $this->assertTrue($bag->save());

        $stored = $this->db->query('SELECT items, paid, total FROM declared_cast_bags')->fetch();

        $this->assertSame(
            '["a","b"]',
            (string) $stored['items'],
            'An un-reverse-cast array reaches the driver as the literal string "Array" — the identical corruption FieldAttributeCastingTest guards for #[Field], reproduced for $casts.',
        );
        $this->assertSame('1', (string) $stored['paid']);
        $this->assertSame('12.5', (string) $stored['total']);

        $reloaded = DeclaredCastBag::find((int) $bag->getAttribute('id'));
        $this->assertNotNull($reloaded);
        $this->assertSame(['a', 'b'], $reloaded->items);
        $this->assertTrue($reloaded->paid);
        $this->assertSame(12.5, $reloaded->total);
    }

    /**
     * The negative control for the control above.
     *
     * A $casts entry pointing at a column the model does not declare must not
     * invent a column. It is here so that a fix which stops consulting
     * getAutoCasts() altogether cannot pass by disabling every cast.
     */
    public function testAModelWithoutAnyCastDeclarationRoundTripsUncast(): void
    {
        $widget = new UncastBag();
        $widget->fill(['payload' => '{"looks":"like json"}']);
        $this->assertTrue($widget->save());

        $stored = $this->db->query('SELECT payload FROM uncast_bags')->fetch();
        $this->assertSame(
            '{"looks":"like json"}',
            (string) $stored['payload'],
            'With no $casts and no #[Field] the raw string must survive. A decoded array here means casts are being guessed rather than declared.',
        );

        $reloaded = UncastBag::find((int) $widget->getAttribute('id'));
        $this->assertNotNull($reloaded);
        $this->assertSame('{"looks":"like json"}', $reloaded->payload);
    }
}
