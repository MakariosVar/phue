<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database\ActiveRecord;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\ActiveRecord\Relations\HasMany;
use Phue\Core\Database\Attributes\SoftDelete;
use Phue\Tests\Support\UsesIsolatedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Author stub with one relation and no other purpose.
 */
class MagicAuthor extends BaseActiveRecord
{
    protected string $table = 'magic_authors';

    public function books(): HasMany
    {
        return $this->hasMany(MagicBook::class, 'author_id');
    }

    /**
     * A zero-argument method that is NOT a relation and has side effects.
     * Public because the framework must survive third-party models like this.
     */
    public function archiveEverything(): bool
    {
        static::$archiveCalls++;

        return $this->delete();
    }

    public static int $archiveCalls = 0;
}

class MagicBook extends BaseActiveRecord
{
    protected string $table = 'magic_books';
}

/**
 * Model used to observe the soft-delete global scope accumulating.
 */
#[SoftDelete]
class MagicNote extends BaseActiveRecord
{
    protected string $table = 'magic_notes';
}

/**
 * Two questions about ActiveRecord that only a reusing caller would ask.
 *
 * 1. __get() is a resolver, and it resolves by *existence* rather than by role.
 *
 *     public function __get(string $key): mixed {
 *         ...
 *         if (method_exists($this, $key)) {
 *             $relation = $this->$key();
 *
 *     `method_exists` matches delete(), save(), forceDelete(), restore(),
 *     validate(), load() — every public zero-argument method on the model and
 *     on BaseActiveRecord itself. The result is discarded unless it is a
 *     Relation, but the *call* already happened. So `$model->delete` in a
 *     template, in a debug dump, in a serialiser, or in a `isset()` probe that
 *     an editor autocompleted, executes a DELETE. The magic getter makes a
 *     write look like a read.
 *
 *     The tests below pin both halves: the dangerous case must stop, and the
 *     lazy-loaded relation must keep working. A fix that simply removes the
 *     method_exists branch passes the first and fails the second.
 *
 * 2. get() and first() re-apply the soft-delete scope on every call, and the
 *     scope appends to $whereClauses without checking whether it is already
 *     there. A reused query object accumulates one clause per terminal call,
 *     so the SQL grows with every read and a query inspected after the fact
 *     no longer matches the query that ran.
 *
 * @package Phue\Tests\Core\Database\ActiveRecord
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class MagicAccessorAndScopeTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MagicAuthor::$archiveCalls = 0;

        $db = $this->isolatedDatabase();

        $db->execute('
            CREATE TABLE magic_authors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ');

        $db->execute('
            CREATE TABLE magic_books (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                author_id INTEGER NOT NULL,
                title TEXT NOT NULL
            )
        ');

        $db->execute('
            CREATE TABLE magic_notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                body TEXT NOT NULL,
                deleted_at DATETIME NULL
            )
        ');

        $db->execute("INSERT INTO magic_authors (name) VALUES ('ada'), ('bob')");
        $db->execute("INSERT INTO magic_books (author_id, title) VALUES (1, 'Notes'), (1, 'Sketch')");
        $db->execute("INSERT INTO magic_notes (body) VALUES ('one'), ('two')");
    }

    private function countAuthors(): int
    {
        return (int) $this->isolatedDatabase()
            ->query('SELECT COUNT(*) as c FROM magic_authors', [])
            ->fetch()['c'];
    }

    public function testReadingAPropertyDoesNotInvokeASideEffectingMethod(): void
    {
        $author = MagicAuthor::query()->where('id', '=', 1)->first();

        $this->assertNotNull($author);

        // A plain property read. Nothing in the expression says "delete".
        $author->archiveEverything;

        $this->assertSame(
            0,
            MagicAuthor::$archiveCalls,
            'Reading a property whose name matches a public zero-argument method executed that method. '
            . '__get() resolves by existence rather than by role, so a read can be a write.'
        );

        $this->assertSame(2, $this->countAuthors(), 'The read deleted a row.');
    }

    public function testReadingTheDeletePropertyDoesNotDeleteTheRow(): void
    {
        $author = MagicAuthor::query()->where('id', '=', 1)->first();

        $this->assertNotNull($author);

        $author->delete;

        $this->assertSame(2, $this->countAuthors(), 'Reading ->delete performed a DELETE.');
    }

    public function testLazyLoadedRelationsStillResolveThroughTheMagicGetter(): void
    {
        $author = MagicAuthor::query()->where('id', '=', 1)->first();

        $this->assertNotNull($author);

        $books = $author->books;

        $this->assertCount(2, $books, 'Lazy loading a relation through __get() stopped working.');
    }

    public function testRelationsAreCachedAfterFirstAccess(): void
    {
        $author = MagicAuthor::query()->where('id', '=', 1)->first();

        $this->assertNotNull($author);

        $this->assertSame($author->books, $author->books);
    }

    public function testAnUnknownPropertyIsNullWithoutTouchingTheDatabase(): void
    {
        $author = MagicAuthor::query()->where('id', '=', 1)->first();

        $this->assertNotNull($author);
        $this->assertNull($author->thisFieldDoesNotExist);
    }

    public function testRepeatedExecutionDoesNotAccumulateSoftDeleteClauses(): void
    {
        $query = MagicNote::query();

        $query->get();
        $sqlAfterFirst = $this->lastSoftDeleteClauseCount($query);

        $query->get();
        $sqlAfterSecond = $this->lastSoftDeleteClauseCount($query);

        $this->assertSame(
            $sqlAfterFirst,
            $sqlAfterSecond,
            'Each terminal call re-applies the soft-delete scope by appending to $whereClauses without '
            . 'checking whether it is already present. A reused query object grows one clause per read.'
        );
    }

    /**
     * Number of soft-delete predicates currently on the builder.
     */
    private function lastSoftDeleteClauseCount(mixed $query): int
    {
        $reflection = new \ReflectionProperty(\Phue\Core\Database\QueryBuilder::class, 'whereClauses');

        $clauses = (array) $reflection->getValue($query);

        return count(array_filter(
            $clauses,
            static fn(array $clause): bool => str_contains($clause['sql'], 'deleted_at')
        ));
    }

    public function testSoftDeletedRowsStayHiddenAcrossAReusedQuery(): void
    {
        $query = MagicNote::query();

        $this->assertCount(2, $query->get());

        $note = $query->first();
        $this->assertNotNull($note);
        $note->delete();

        $this->assertCount(
            1,
            $query->get(),
            'The global scope stopped excluding trashed rows on a reused query object.'
        );
    }
}