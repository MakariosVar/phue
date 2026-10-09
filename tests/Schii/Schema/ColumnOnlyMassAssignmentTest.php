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
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use Phue\Tests\Support\SqliteFactory;

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
     * A client posting a #[Column]-only property name gets no write.
     *
     * Accepted rather than rejected, and deliberately so: the property is part
     * of the entity, so it is not an unknown field, and every unwritable field
     * behaves this way already — App\Models\User::$password_hash is declared
     * writable: false, and a payload carrying it is stored and the value
     * dropped. Rejecting it here while ignoring it there would mean two
     * behaviours for the same concept.
     *
     * The distinction that matters is against a genuinely undeclared field,
     * which IS refused, and is asserted separately below.
     */
public function testPostingAColumnOnlyPropertyIsAcceptedButNotWritten(): void
    {
        $response = $this->endpoint->store(
            new Request('POST', '/schii/items', [], [
                'real_column' => 'kept',
                'display_only' => 'injected',
            ]),
            'items'
        );

        $this->assertSame(
            201,
            $response->getStatusCode(),
            '#[Column] declares the property as part of the entity, so the payload is not malformed.',
        );

        $row = $this->db->query('SELECT real_column, display_only FROM column_only_items')->fetch();

        $this->assertSame(
            'kept',
            (string) $row['real_column'],
            'The declared column must be written.',
        );
        $this->assertNull(
            $row['display_only'],
            '#[Column] must never grant write access — otherwise adding a label to a model would hand a client a column.',
        );
    }

    /**
     * The contrast that gives the test above its meaning.
     *
     * An unwritable-but-declared property is ignored; a property nobody declared
     * at all is refused. If #[Column] alone made a field mass-assignable, this
     * would move into the first case and the boundary would be gone.
     */
    public function testAnUndeclaredPropertyIsStillRefusedAsUnknown(): void
    {
        $response = $this->endpoint->store(
            new Request('POST', '/schii/items', [], [
                'real_column' => 'kept',
                'no_attribute_at_all' => 'x',
            ]),
            'items'
        );

        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('no_attribute_at_all', $data['errors']);
    }

    /**
     * The row carries only what was declared writable.
     *
     * Proves the value really is dropped rather than merely unreported.
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