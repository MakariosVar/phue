<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database\ActiveRecord;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use Phue\Tests\Support\SqliteFactory;

/**
 * The cast a property receives is a PERSISTENCE decision, and persistence is
 * Core. These tests pin that #[Field] is a Core attribute and that the cast it
 * declares actually applies.
 *
 * The defect this guards
 * ----------------------
 * #[Field] used to live in the admin module (Phue\Modules\Schii\Attribute\Field)
 * and Core's own BaseActiveRecord::getAutoCasts() read THAT class by name:
 *
 *     $property->getAttributes(\Phue\Modules\Schii\Attribute\Field::class)
 *
 * A module is optional; Core is not. So in an application built from a
 * template that does not install the admin module, no property matched, the
 * auto-cast map came back empty, and every typed property crossed the driver
 * boundary uncast:
 *
 *   #[Field(type: 'json')] public array $roles
 *       -> array handed straight to the driver -> stored as the literal
 *          string "Array", and read back as the string "Array".
 *
 * Nothing raised. The model loaded, the query ran, the row was written — to
 * the wrong shape. The failure surfaces later, when something reads the value
 * and finds a string where an array was declared.
 *
 * Why a round-trip and not a cast-map assertion
 * --------------------------------------------
 * getAutoCasts() is protected and its cache is static per class, so asserting
 * on the map would test the implementation rather than the contract, and would
 * poison that cache for the rest of the suite. What matters to an application
 * author is the value that comes back out of the database, so that is what is
 * asserted: fill a typed value, save, then read it back through a fresh
 * instance obtained with find().
 *
 * Values go in through fill(), not through direct property assignment. These
 * stub properties are declared and public, so PHP writes them straight onto the
 * object and never reaches __set(), leaving the internal attribute bag empty —
 * save() then issues INSERT INTO t () VALUES (), which is a syntax error rather
 * than a silent no-op. fill() is the same entry point the Schii endpoint uses,
 * so the test exercises the path a real model takes.
 *
 * The negative test matters just as much. Without it, a cast that applied to
 * EVERY typed property would pass every positive case while quietly inventing
 * arrays out of strings the developer never declared.
 */
class CastWidget extends BaseActiveRecord
{
    protected string $table = 'cast_widgets';

    #[Field(type: 'json')]
    #[Column(label: 'Roles')]
    public array $roles = [];

    #[Field(type: 'boolean')]
    #[Column(label: 'Active')]
    public bool $active = false;

    #[Field(type: 'integer')]
    #[Column(label: 'Quantity')]
    public int $quantity = 0;

    #[Field(type: 'float')]
    #[Column(label: 'Price')]
    public float $price = 0.0;

    #[Field(type: 'text')]
    #[Column(label: 'Name')]
    public string $name = '';
}

/**
 * Same PHP type on the property, but no #[Field]. Proves the cast is declared
 * rather than inferred from the type.
 */
class UnannotatedWidget extends BaseActiveRecord
{
    protected string $table = 'unannotated_widgets';

    public string $payload = '';
}

#[Group('integration')]
final class FieldAttributeCastingTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Owned in-memory SQLite, never the ambient connection: this file issues
        // SQLite DDL, and a test elsewhere that boots the real application
        // replaces the global connection with MariaDB process-wide. See the
        // same defence in KnownVulnerabilityTest::setUp().
        $this->db = SqliteFactory::memory();
        $connection = $this->db;
        BaseActiveRecord::setConnection($connection);
        BaseActiveRecord::setConnectionResolver(static fn(): Connection => $connection);

        $this->db->execute('DROP TABLE IF EXISTS cast_widgets');
        $this->db->execute('
            CREATE TABLE cast_widgets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                roles TEXT,
                active TEXT,
                quantity TEXT,
                price TEXT,
                name TEXT
            );
        ');

        $this->db->execute('DROP TABLE IF EXISTS unannotated_widgets');
        $this->db->execute('
            CREATE TABLE unannotated_widgets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payload TEXT
            );
        ');
    }

    /**
     * The attribute the framework reads is a Core class, not a module class.
     *
     * Stated as a location assertion rather than a behavioural one because the
     * behavioural version cannot detect the regression: an application that
     * DOES install the admin module behaves identically either way. The defect
     * only exists for the application that did not.
     */
    public function testFieldAttributeIsResolvedFromTheCoreNamespace(): void
    {
        $this->assertSame(
            'Phue\Core\Database\Attributes\Field',
            (new \ReflectionClass(Field::class))->getName(),
            '#[Field] must be a Core attribute: Core persistence reads it by name and cannot depend on an optional module for it.',
        );
    }

    /**
     * A #[Field(type: 'json')] array survives the database as an array.
     *
     * The regression is visible in the raw column as much as in the model: an
     * uncast array reaches SQLite through the driver as the literal string
     * "Array", so asserting on the stored text distinguishes a real json
     * encode from a driver that happens to be lenient.
     */
    public function testJsonTypedPropertyIsCastToJsonOnBothWriteAndRead(): void
    {
        $widget = new CastWidget();
        $widget->fill(['roles' => ['admin', 'editor']]);
        $this->assertTrue($widget->save());

        $stored = $this->db->query('SELECT roles FROM cast_widgets')->fetch();
        $this->assertSame(
            '["admin","editor"]',
            (string) $stored['roles'],
            'An uncast array property is written as the literal string "Array" — that is what a missing json cast looks like.',
        );

        $reloaded = CastWidget::find((int) $widget->getAttribute('id'));
        $this->assertNotNull($reloaded);
        $this->assertSame(['admin', 'editor'], $reloaded->roles);
    }

    public function testBooleanTypedPropertyRoundTripsAsBoolean(): void
    {
        $widget = new CastWidget();
        $widget->fill(['name' => 'toggle', 'active' => true]);
        $this->assertTrue($widget->save());

        $reloaded = CastWidget::find((int) $widget->getAttribute('id'));
        $this->assertNotNull($reloaded);
        $this->assertTrue($reloaded->active);
    }

    public function testNumericTypedPropertiesRoundTripAsNumbers(): void
    {
        $widget = new CastWidget();
        $widget->fill(['quantity' => 7, 'price' => 12.5]);
        $this->assertTrue($widget->save());

        $reloaded = CastWidget::find((int) $widget->getAttribute('id'));
        $this->assertNotNull($reloaded);
        $this->assertSame(7, $reloaded->quantity);
        $this->assertSame(12.5, $reloaded->price);
    }

/**
 * The negative control: without #[Field] there is no cast, so the value keeps
 * whatever type it arrived as.
 *
 * A hypothetical "cast everything that is typed" rule — which is what a fix
 * that guessed the cast from the PHP type instead of from the declaration would
 * produce — decodes this exact string and passes silently. Asserting that the
 * raw string survives catches that, because the corrupted column is a string
 * where the declared type says otherwise.
 */
public function testPropertyWithoutFieldAttributeReceivesNoCast(): void
{
    $widget = new UnannotatedWidget();
    $widget->fill(['payload' => '{"looks":"like json"}']);

    $this->assertSame(
        '{"looks":"like json"}',
        $widget->payload,
        'With no #[Field(type: \'json\')] the raw string must survive untouched. A decoded array here would mean casts are inferred from the PHP type rather than declared — the mirror image of the bug this file guards.',
    );
}

/**
 * The converse, on the identical input: the declared cast IS applied.
 *
 * Pairing this with the negative control is what makes either one meaningful.
 * Asserted on the declared property rather than getAttribute() because that is
 * where hydration lands: setAttribute() stores the raw value in the attribute
 * bag and applies the cast to the declared property, so the two legitimately
 * disagree until the value comes back out of the database.
 */
public function testFieldAttributeAppliesItsDeclaredCast(): void
{
    $widget = new CastWidget();
    $widget->fill(['roles' => '{"looks":"like json"}']);

    $this->assertSame(
        ['looks' => 'like json'],
        $widget->roles,
        '#[Field(type: \'json\')] must json-decode on hydration. The raw string here would mean the declared cast is never applied.',
    );
}

    /**
     * The attribute carries no dependency on any module.
     *
     * A #[Field] that imported a module class could not be loaded by an
     * application without that module, which would turn a missing optional
     * module into a fatal error on every model.
     */
    public function testFieldAttributeSourceImportsNothingFromAnyModule(): void
    {
        $path = (new \ReflectionClass(Field::class))->getFileName();
        $this->assertIsString($path);

        $this->assertSame(
            0,
            preg_match_all('/^\s*use\s+Phue\\\\Modules\\\\/m', (string) file_get_contents($path)),
            'A Core attribute must not import from an optional module: the import would make the class unloadable wherever that module is absent.',
        );
    }
}