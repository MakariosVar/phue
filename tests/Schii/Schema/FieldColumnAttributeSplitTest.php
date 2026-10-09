<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Schema;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use Phue\Tests\Support\SqliteFactory;

/**
 * A model is described by two attributes, and each one is trusted for exactly
 * one job.
 *
 *   #[Field]   Core.   type, rules, required, writable, readonly, column,
 *                       primaryKey, indexed. Everything that decides whether a
 *                       value EXISTS, how it is CAST, and whether it may be
 *                       WRITTEN.
 *   #[Column]  Schii.  label, widget, searchable, filterable, sortable,
 *                       options. Everything that decides how the value LOOKS
 *                       in an admin console.
 *
 * They were one attribute until #[Field] moved to Core, and that is what made
 * the split necessary: a model in a project with no admin module could not
 * describe its persistence, because the declaration of "this is a json column"
 * was shipped inside the admin module. Splitting them keeps Core free of
 * presentation and keeps presentation free of being load-bearing.
 *
 * The load-bearing rule this file exists to pin
 * ---------------------------------------------
 * #[Column] alone does not make a property writable. That is not tidiness, it
 * is the difference between a display hint and a write permission. If a
 * property could be mass-assigned because it said how it should look, then
 * adding a label to a model would silently hand a client write access to a
 * column — and the attribute named "Column" would be the one granting it.
 */

/** Both attributes on one property: the normal case. */
#[UI(label: 'Split Items', icon: 'box')]
class SplitItem extends BaseActiveRecord
{
    protected string $table = 'split_items';

    #[Field(type: 'text', required: true, indexed: true)]
    #[Column(label: 'Item Name', searchable: true, sortable: true, options: ['a' => 'A'])]
    public string $name = '';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    #[Column(label: 'Identifier')]
    public int $id = 0;

    #[Field(type: 'string', writable: false, readonly: true, column: false)]
    #[Column(label: 'Secret Token')]
    public string $token = '';
}

/** #[Field] alone: persistence declared, presentation defaulted. */
class FieldOnlyItem extends BaseActiveRecord
{
    protected string $table = 'field_only_items';

    #[Field(type: 'text')]
    public string $bare_column = '';
}

/** #[Column] alone: presentation declared, persistence NOT declared. */
class ColumnOnlyItem extends BaseActiveRecord
{
    protected string $table = 'column_only_items';

    #[Column(label: 'Display Only', searchable: true)]
    public string $display_only = '';

    #[Field(type: 'text')]
    #[Column(label: 'Real Column')]
    public string $real_column = '';
}

#[Group('unit')]
final class FieldColumnAttributeSplitTest extends TestCase
{
    private SchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SchemaRegistry();
    }

    /**
     * @return array<string, \Phue\Modules\Schii\Schema\FieldSchema>
     */
    private function schemaOf(string $class): array
    {
        return $this->registry->get($class)->fields;
    }

    // ---------------------------------------------------------------- presence

    public function testPropertyWithNeitherAttributeIsNotPartOfTheEntity(): void
    {
        $schema = $this->schemaOf(FieldOnlyItem::class);

        $this->assertArrayHasKey('bare_column', $schema);
        $this->assertCount(1, $schema, 'An undeclared property must not appear in the schema.');
    }

    // ------------------------------------------------------------------- merge

    public function testBothAttributesContributeTheirOwnHalf(): void
    {
        $name = $this->schemaOf(SplitItem::class)['name'];

        // From #[Field]
        $this->assertSame('text', $name->type);
        $this->assertTrue($name->required);
        $this->assertTrue($name->indexed);
        $this->assertTrue($name->writable);

        // From #[Column]
        $this->assertSame('Item Name', $name->label);
        $this->assertTrue($name->searchable);
        $this->assertTrue($name->sortable);
        $this->assertSame(['a' => 'A'], $name->options);
    }

    public function testFieldOnlyPropertyStillAppearsWithHumanizedLabel(): void
    {
        $field = $this->schemaOf(FieldOnlyItem::class)['bare_column'];

        $this->assertSame('Bare Column', $field->label, 'Without #[Column] the label falls back to a humanized property name.');
        $this->assertSame('input', $field->widget);
        $this->assertFalse($field->searchable);
        $this->assertFalse($field->filterable);
        $this->assertFalse($field->sortable);
        $this->assertSame([], $field->options);
    }

    public function testWritableFalseOnFieldIsHonouredAlongsideAColumn(): void
    {
        $token = $this->schemaOf(SplitItem::class)['token'];

        $this->assertFalse($token->writable);
        $this->assertTrue($token->readonly);
        $this->assertFalse($token->column);
        $this->assertSame('Secret Token', $token->label, 'A #[Column] label must not be able to imply writability.');
    }

    public function testPrimaryKeyAndReadonlyAreCarriedFromField(): void
    {
        $id = $this->schemaOf(SplitItem::class)['id'];

        $this->assertTrue($id->primaryKey);
        $this->assertTrue($id->readonly);
    }

    /**
     * `writable` stays independent of `primaryKey`.
     *
     * Worth pinning because it is the natural thing to assume and it is false.
     * A primary key is stripped from inbound payloads by an explicit unset in
     * extractInputData(), not by its writable flag, so #[Field(primaryKey: true)]
     * alone does not make a field unwritable. That the strip actually happens
     * is asserted end-to-end by
     * SchiiEndpointEdgeCasesTest::testStoreIgnoresClientSuppliedPrimaryKey —
     * this test only records that the schema does not pretend otherwise.
     */
    public function testPrimaryKeyDoesNotImplicitlyClearWritable(): void
    {
        $id = $this->schemaOf(SplitItem::class)['id'];

        $this->assertTrue(
            $id->writable,
            'Key protection is an explicit strip in extractInputData(). Coupling it to the writable flag here would describe a mechanism the endpoint does not use.',
        );
    }

    // ------------------------------------------------- the security boundary

    public function testColumnOnlyPropertyIsPresentButNotWritable(): void
    {
        $display = $this->schemaOf(ColumnOnlyItem::class)['display_only'];

        $this->assertArrayHasKey(
            'display_only',
            $this->schemaOf(ColumnOnlyItem::class),
            '#[Column] declares that the property is part of the entity, so it belongs in the schema.',
        );

        $this->assertFalse(
            $display->writable,
            '#[Column] describes presentation. It must never grant write access — that would let a label hand a client a column.',
        );
        $this->assertFalse($display->column, 'No #[Field] means no declared database column.');
        $this->assertFalse($display->required);
        $this->assertSame([], $display->rules);
    }

    public function testColumnOnlyPropertyCarriesItsPresentation(): void
    {
        $display = $this->schemaOf(ColumnOnlyItem::class)['display_only'];

        $this->assertSame('Display Only', $display->label);
        $this->assertTrue($display->searchable);
    }

    public function testColumnOnlyPropertyDoesNotDisturbItsNeighbour(): void
    {
        $schema = $this->schemaOf(ColumnOnlyItem::class);

        $this->assertTrue($schema['real_column']->writable, 'A neighbouring #[Column] must not grant or revoke writability.');
        $this->assertSame('Real Column', $schema['real_column']->label);
    }
}

#[Group('integration')]
final class ColumnOnlyMassAssignmentTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Connection $db;
    private ModelEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = SqliteFactory::memory();
        $connection = $this->db;
        BaseActiveRecord::setConnection($connection);
        BaseActiveRecord::setConnectionResolver(static fn(): Connection => $connection);

        $this->db->execute('DROP TABLE IF EXISTS column_only_items');
        $this->db->execute('
            CREATE TABLE column_only_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                display_only TEXT,
                real_column TEXT
            );
        ');

        $this->endpoint = new ModelEndpoint(new SchemaRegistry());
        $this->endpoint->registerEntity('items', ColumnOnlyItem::class);
    }

    /**
     * A client posting a #[Column]-only property name gets no write, and no
     * "unknown field" rejection either.
     *
     * The rejection is the subtle half. extractInputData() intersects the
     * payload with writable schema fields, so the value is dropped and the row
     * is written correctly — but validateData() runs first and treats the
     * property as unknown, answering 422. Both halves are correct; this asserts
     * the one an application author actually sees.
     */
    public function testPostingAColumnOnlyPropertyIsRejectedAsUnknown(): void
    {
        $response = $this->endpoint->store(
            new Request('POST', '/schii/items', [], [
                'real_column' => 'kept',
                'display_only' => 'injected',
            ]),
            'items'
        );

        $this->assertSame(
            422,
            $response->getStatusCode(),
            '#[Column] alone must not make a property a valid write target.',
        );

        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('display_only', $data['errors']);
    }

    /**
     * The same property, presented but not persisted.
     *
     * Proves the value really is refused rather than merely reported: the row
     * that lands carries only the declared column.
     */
    public function testColumnOnlyPropertyIsNeverWrittenToTheRow(): void
    {
        $this->endpoint->store(
            new Request('POST', '/schii/items', [], ['real_column' => 'kept']),
            'items'
        );

        $row = $this->db->query('SELECT real_column, display_only FROM column_only_items')->fetch();

        $this->assertSame('kept', (string) $row['real_column']);
        $this->assertNull($row['display_only']);
    }
}