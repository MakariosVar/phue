<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Endpoint;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\RbacManager;
use Phue\Modules\Schii\Attribute\Column;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Support\UsesIsolatedDatabase;

/**
 * A model with a searchable display column, which is the shape the options
 * endpoint is expected to resolve to (primary key as value, searchable column
 * as label).
 */
#[UI(label: 'Option Roles')]
class OptionRole extends BaseActiveRecord
{
    protected string $table = 'option_roles';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'string')]
    #[Column(label: 'Title', searchable: true)]
    public string $title = '';

    #[Field(type: 'string')]
    #[Column(label: 'Note')]
    public string $note = '';
}

/**
 * Nothing here is searchable, so the endpoint must fall back to the first
 * textual non-primary-key column rather than to an empty list.
 */
#[UI(label: 'Option Blinds')]
class OptionBlind extends BaseActiveRecord
{
    protected string $table = 'option_blinds';

    #[Field(type: 'integer', primaryKey: true, readonly: true)]
    #[Column(label: 'ID')]
    public ?int $id = null;

    #[Field(type: 'integer')]
    #[Column(label: 'Counter')]
    public int $counter = 0;

    #[Field(type: 'string')]
    #[Column(label: 'Nickname')]
    public string $nickname = '';
}

/**
 * No primary key anywhere in the schema: there is no value column to offer, so
 * the endpoint must refuse rather than emit rows keyed on null.
 */
#[UI(label: 'Option Keyless')]
class OptionKeyless extends BaseActiveRecord
{
    protected string $table = 'option_keyless';

    #[Field(type: 'string')]
    #[Column(label: 'Title')]
    public string $title = '';
}

#[Group('integration')]
final class RelationOptionsEndpointTest extends TestCase
{
    use UsesIsolatedDatabase;

    private Connection $db;
    private ModelEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = BaseActiveRecord::getConnection();
        $this->db->execute('DROP TABLE IF EXISTS option_roles');
        $this->db->execute("CREATE TABLE option_roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            note TEXT NOT NULL
        )");
        $this->db->execute("INSERT INTO option_roles (title, note) VALUES ('Administrator', 'root notes')");
        $this->db->execute("INSERT INTO option_roles (title, note) VALUES ('Editor', 'second')");
        $this->db->execute("INSERT INTO option_roles (title, note) VALUES ('Author', 'third')");

        $this->db->execute('DROP TABLE IF EXISTS option_blinds');
        $this->db->execute("CREATE TABLE option_blinds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            counter INTEGER NOT NULL DEFAULT 0,
            nickname TEXT NOT NULL
        )");
        $this->db->execute("INSERT INTO option_blinds (counter, nickname) VALUES (7, 'Nemo')");

        $this->db->execute('DROP TABLE IF EXISTS option_keyless');
        $this->db->execute("CREATE TABLE option_keyless (
            title TEXT NOT NULL
        )");

        $this->endpoint = new ModelEndpoint(
            new SchemaRegistry(),
            entityNamespace: 'Phue\\Tests\\Schii\\Endpoint'
        );
    }

    private function decode(Response $response): array
    {
        return (array) json_decode($response->getContent(), true);
    }

    /**
     * @return array<int, array{value: mixed, label: string}>
     */
    private function rows(string $entity, array $query = []): array
    {
        $res = $this->endpoint->relationOptions(
            new Request('GET', "/api/schii/relation/{$entity}/options", $query),
            ['entity' => $entity],
        );

        return $this->decode($res)['data'];
    }

    public function testReturnsPrimaryKeyValueAndSearchableLabel(): void
    {
        $rows = $this->rows('option-role');

        self::assertCount(3, $rows);
        self::assertSame(
            ['Administrator', 'Editor', 'Author'],
            array_column($rows, 'label')
        );
        self::assertSame([1, 2, 3], array_column($rows, 'value'));
    }

    public function testMetaReportsTheResolvedColumns(): void
    {
        $res = $this->endpoint->relationOptions(
            new Request('GET', '/api/schii/relation/option-role/options'),
            ['entity' => 'option-role'],
        );
        $meta = $this->decode($res)['meta'];

        // The dropdown has to filter on the same column it labels by; meta is
        // how the frontend learns that without re-deriving the rule.
        self::assertSame('title', $meta['label']);
        self::assertSame('id', $meta['value']);
    }

    public function testSearchQueryFiltersOnTheLabelColumn(): void
    {
        // The search must hit the label column (title), not the id or the note.
        self::assertSame(['Author'], array_column($this->rows('option-role', ['q' => 'Auth']), 'label'));
    }

    public function testSearchDoesNotMatchTheNonLabelColumns(): void
    {
        // "second" appears only in the note column, which is not the label.
        self::assertSame([], $this->rows('option-role', ['q' => 'second']));
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $rows = $this->rows('option-role', ['q' => 'AUTHOR']);

        self::assertSame(['Author'], array_column($rows, 'label'));
    }

    public function testSearchTreatsWildcardsAsLiteralCharacters(): void
    {
        // "%" must not turn into a match-everything wildcard, otherwise a
        // literal "%" in the box returns the whole table.
        self::assertSame([], $this->rows('option-role', ['q' => '%']));
    }

    public function testEmptySearchReturnsEverything(): void
    {
        self::assertCount(3, $this->rows('option-role', ['q' => '']));
    }

    public function testFallsBackToFirstTextualColumnWhenNothingIsSearchable(): void
    {
        $rows = $this->rows('option-blind');

        self::assertSame([['value' => 1, 'label' => 'Nemo']], $rows);
    }

    public function testLimitIsHonoured(): void
    {
        self::assertCount(2, $this->rows('option-role', ['limit' => 2]));
    }

    public function testLimitCannotExceedTheCeiling(): void
    {
        $res = $this->endpoint->relationOptions(
            new Request('GET', '/api/schii/relation/option-role/options', ['limit' => '100000']),
            ['entity' => 'option-role'],
        );
        $meta = $this->decode($res)['meta'];

        self::assertSame(200, $meta['limit']);
    }

    public function testLimitCannotBeZeroOrNegative(): void
    {
        $res = $this->endpoint->relationOptions(
            new Request('GET', '/api/schii/relation/option-role/options', ['limit' => '-5']),
            ['entity' => 'option-role'],
        );

        self::assertSame(1, $this->decode($res)['meta']['limit']);
    }

    public function testUnknownEntityIsNotFound(): void
    {
        $res = $this->endpoint->relationOptions(
            new Request('GET', '/api/schii/relation/nope/options'),
            ['entity' => 'nope'],
        );

        self::assertSame(404, $res->getStatusCode());
    }

    public function testModelWithoutAPrimaryKeyIsNotFound(): void
    {
        $res = $this->endpoint->relationOptions(
            new Request('GET', '/api/schii/relation/option-keyless/options'),
            ['entity' => 'option-keyless'],
        );

        self::assertSame(404, $res->getStatusCode());
    }

    public function testAcceptsAStringParameterLikeTheOtherEntityHandlers(): void
    {
        $res = $this->endpoint->relationOptions(
            new Request('GET', '/api/schii/relation/option-role/options'),
            'option-role',
        );

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(3, $this->decode($res)['data']);
    }

    public function testPluralSlugResolves(): void
    {
        self::assertCount(3, $this->rows('option-roles'));
    }

    public function testAuthorizedDecoratorExposesTheOperation(): void
    {
        self::assertTrue(
            method_exists(AuthorizedModelEndpoint::class, 'relationOptions'),
            'the RBAC decorator must expose the operation or the guarded route 500s'
        );
    }

    public function testAuthorizedDecoratorReturns401WithoutIdentity(): void
    {
        $guarded = new AuthorizedModelEndpoint(
            $this->endpoint,
            new AuthManager([]),
            new RbacManager(__DIR__ . '/../Stubs/rbac_empty.php'),
            new SchemaRegistry(),
        );

        $res = $guarded->relationOptions(
            new Request('GET', '/api/schii/relation/option-role/options'),
            ['entity' => 'option-role'],
        );

        self::assertSame(401, $res->getStatusCode());
    }
}
