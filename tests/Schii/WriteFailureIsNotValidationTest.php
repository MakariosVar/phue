<?php

declare(strict_types=1);

namespace Phue\Tests\Schii;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use Phue\Tests\Support\SqliteFactory;

/**
 * A write that fails is not a write that was refused.
 *
 * The defect
 * ----------
 * save() returning false was reported as:
 *
 *     422 { "error": "Validation failed", "errors": $model->getErrors() }
 *
 * getErrors() had no writer anywhere in src/, so that list was always empty. A
 * write that failed for a reason unrelated to the input therefore reached the
 * caller as a 422 blaming their input, with no reason given.
 *
 * Two shapes, two outcomes
 * -----------------------
 * A driver fault (missing table, bad column) THROWS and never becomes a
 * Response at all — it reaches the error handler and renders as a 500. That
 * was already the case, and it is asserted here so it stays that way: the
 * endpoint must not absorb a database fault into a validation answer.
 *
 * The silent false is the other shape, and the dangerous one, because it is
 * indistinguishable from a refusal unless the error store is consulted:
 *
 *   - refused model   -> save() false, getErrors() populated -> 422 + reasons
 *   - failed write    -> save() false, getErrors() empty     -> 500
 *
 * A write stub stands in for the second, since provoking it needs a driver
 * that reports zero affected rows.
 */
class WriteFailingProduct extends BaseActiveRecord
{
    protected string $table = 'write_failing_products';

    #[Field(type: 'string')]
    public string $name = '';

    /**
     * A write the driver accepts but that affects nothing, with no rule
     * broken — the same state save() reaches when an update matches no row.
     */
    public function save(): bool
    {
        $this->setErrors([]);

        return false;
    }
}

class DriverFaultingProduct extends BaseActiveRecord
{
    protected string $table = 'driver_faulting_products';

    #[Field(type: 'string')]
    public string $name = '';
}

#[Group('integration')]
final class WriteFailureIsNotValidationTest extends TestCase
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

        // Created: the failing-write stub reports failure on its own.
        // NOT created: driver_faulting_products, so the driver raises.
        $this->db->execute('DROP TABLE IF EXISTS write_failing_products');
        $this->db->execute('
            CREATE TABLE write_failing_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            );
        ');

        $this->endpoint = new ModelEndpoint(new SchemaRegistry());
        $this->endpoint->registerEntity('silent', WriteFailingProduct::class);
        $this->endpoint->registerEntity('faulting', DriverFaultingProduct::class);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(string $entity): array
    {
        $response = $this->endpoint->store(
            new Request('POST', "/schii/{$entity}", [], ['name' => 'fine']),
            $entity
        );

        return [
            $response->getStatusCode(),
            (array) json_decode((string) $response->getContent(), true),
        ];
    }

    public function testAFailedWriteIsAServerFaultNotAClientOne(): void
    {
        [$status, $body] = $this->post('silent');

        $this->assertSame(
            500,
            $status,
            'The model was valid and the write simply did not happen. 422 would tell the caller to change something that was already correct.',
        );
    }

    public function testAFailedWriteDoesNotClaimValidationFailed(): void
    {
        [, $body] = $this->post('silent');

        $this->assertStringNotContainsStringIgnoringCase(
            'validation',
            (string) ($body['error'] ?? ''),
            'The response must not blame validation for a write that never got that far.',
        );
    }

    public function testAFailedWriteCarriesNoReasons(): void
    {
        [, $body] = $this->post('silent');

        $this->assertArrayNotHasKey(
            'errors',
            $body,
            'An empty list under "Validation failed" is the exact response this file exists to prevent.',
        );
    }

    /**
     * The other shape, kept separate on purpose.
     *
     * A driver fault throws. Absorbing it into a Response is what turned a
     * database problem into an input problem in the first place, so the
     * endpoint is required to let it out.
     */
    public function testADriverFaultIsNotAbsorbedIntoAValidationResponse(): void
    {
        try {
            $this->post('faulting');
            $this->fail('A driver fault must not be turned into a Response; the error handler reports it as a 500.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
    }
}