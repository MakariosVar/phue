<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Validation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use Phue\Tests\Support\SqliteFactory;

/**
 * Requires presence through the rules alone.
 */
class RuleRequiredProduct extends BaseActiveRecord
{
    protected string $table = 'rule_required_products';

    #[Field(type: 'text', rules: ['required'])]
    public string $name = '';
}

/**
 * Declares both required: and rules: ['required'] — the two must agree.
 */
class DoublyRequiredProduct extends BaseActiveRecord
{
    protected string $table = 'doubly_required_products';

    #[Field(type: 'text', required: true, rules: ['required'])]
    public string $name = '';
}

#[Group('integration')]
final class RuleEnforcementEndpointTest extends TestCase
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

        $this->db->execute('DROP TABLE IF EXISTS validated_products');
        $this->db->execute('
            CREATE TABLE validated_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                price REAL,
                quantity INTEGER,
                contact TEXT
            );
        ');

        $this->db->execute('DROP TABLE IF EXISTS rule_required_products');
        $this->db->execute('
            CREATE TABLE rule_required_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            );
        ');

        $this->db->execute('DROP TABLE IF EXISTS doubly_required_products');
        $this->db->execute('
            CREATE TABLE doubly_required_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            );
        ');

        $this->endpoint = new ModelEndpoint(new SchemaRegistry());
        $this->endpoint->registerEntity('products', \Phue\Tests\Schii\Validation\ValidatedProduct::class);
        $this->endpoint->registerEntity('rulerequired', RuleRequiredProduct::class);
        $this->endpoint->registerEntity('doublyrequired', DoublyRequiredProduct::class);
    }

    private function store(array $payload, string $entity = 'products'): Response
    {
        return $this->endpoint->store(new Request('POST', "/schii/{$entity}", [], $payload), $entity);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function decode(Response $response): array
    {
        return [
            $response->getStatusCode(),
            (array) json_decode((string) $response->getContent(), true),
        ];
    }

    // ------------------------------------------------------------ min / max

    /**
     * The divergence that motivated moving enforcement to one place.
     *
     * The admin form used to answer "must be at least 1000 characters" for
     * this value and refuse to submit it; the server compared the number and
     * stored it. Whatever the form says, this must be a number bound.
     */
    public function testMinOnANumericFieldBoundsTheValueNotTheLength(): void
    {
        [$status] = $this->decode($this->store([
            'name' => 'Widget',
            'price' => 1200,
        ]));

        $this->assertSame(201, $status, '1200 satisfies min:1000 on a float field.');

        $stored = $this->db->query('SELECT price FROM validated_products')->fetch();
        $this->assertEqualsWithDelta(1200.0, (float) $stored['price'], 0.001);
    }

    public function testMinOnANumericFieldStillRejectsAValueBelowIt(): void
    {
        [$status, $body] = $this->decode($this->store([
            'name' => 'Widget',
            'price' => 999,
        ]));

        $this->assertSame(422, $status);
        $this->assertArrayHasKey('price', $body['errors']);
    }

    public function testMinOnAStringFieldBoundsTheLength(): void
    {
        [$status] = $this->decode($this->store([
            'name' => 'ab',
            'price' => 1200,
        ]));

        $this->assertSame(422, $status, 'min:3 on a text field is a length bound.');

        [$okStatus] = $this->decode($this->store([
            'name' => 'abc',
            'price' => 1200,
        ]));

        $this->assertSame(201, $okStatus);
    }

    public function testStringFieldHoldingDigitsIsStillLengthBounded(): void
    {
        [$status] = $this->decode($this->store([
            'name' => '1200',
            'price' => 1200,
        ]));

        $this->assertSame(201, $status, 'A numeric-looking string on a text field is bounded by its length, not its value.');
    }

    public function testMaxOnAStringFieldRejectsAnOverlongValue(): void
    {
        [$status, $body] = $this->decode($this->store([
            'name' => 'abcdefghijk',
            'price' => 1200,
        ]));

        $this->assertSame(422, $status);
        $this->assertStringContainsString('characters', (string) $body['errors']['name']);
    }

    // ------------------------------------------------------- simple rules

    public function testEmailRuleRejectsAMalformedAddress(): void
    {
        [$status, $body] = $this->decode($this->store([
            'name' => 'Widget',
            'price' => 1200,
            'contact' => 'not-an-address',
        ]));

        $this->assertSame(422, $status);
        $this->assertArrayHasKey('contact', $body['errors']);
    }

    public function testIntegerRuleRejectsAFractionalQuantity(): void
    {
        [$status, $body] = $this->decode($this->store([
            'name' => 'Widget',
            'price' => 1200,
            'quantity' => 1.5,
        ]));

        $this->assertSame(422, $status);
        $this->assertArrayHasKey('quantity', $body['errors']);
    }

    /**
     * A numeric column is rejected for non-numeric input whether or not the
     * author restated `numeric`, because the type already said so.
     */
    public function testNumericTypeImpliesNumericWithoutTheRule(): void
    {
        [$status, $body] = $this->decode($this->store([
            'name' => 'Widget',
            'price' => 'free',
        ]));

        $this->assertSame(422, $status);
        $this->assertArrayHasKey('price', $body['errors']);
    }

    // ---------------------------------------------------------- required

    /**
     * `required` inside rules means what `required: true` means.
     *
     * Asserted because the two used to disagree in a way nobody could see:
     * rules: ['required'] was accepted, skipped by both evaluation loops, and
     * enforced by neither. The presence check happened only for the field
     * flag, so a field that declared it only in its rules was optional.
     */
    public function testRequiredInsideRulesEnforcesPresence(): void
    {
        [$status, $body] = $this->decode($this->store(['price' => 1200], 'rulerequired'));

        $this->assertSame(422, $status);
        $this->assertSame(
            "Field 'name' is required",
            $body['errors']['name'],
        );
    }

    public function testRequiredInsideRulesStillPermitsAPartialUpdate(): void
    {
        $created = $this->store(['name' => 'Widget'], 'rulerequired');
        [, $createdBody] = $this->decode($created);
        $id = $createdBody['data']['id'];

        $updated = $this->endpoint->update(
            new Request('PUT', "/schii/rulerequired/{$id}", [], []),
            ['entity' => 'rulerequired', 'id' => $id],
        );

        $this->assertSame(
            200,
            $updated->getStatusCode(),
            'Requiring presence on update would make every partial update a 422.',
        );
    }

    public function testFieldFlagAndRulesRequiredAgree(): void
    {
        [$status, $body] = $this->decode($this->store([], 'doublyrequired'));

        $this->assertSame(422, $status);
        $this->assertSame("Field 'name' is required", $body['errors']['name']);
    }

    public function testAnAbsentValueIsSkippedRatherThanValidated(): void
    {
        [$status] = $this->decode($this->store(['name' => 'Widget'], 'products'));

        $this->assertSame(
            201,
            $status,
            'price and quantity are absent and not required; a missing value has no type to fail against.',
        );
    }
}