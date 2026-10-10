<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\SoftDelete;
use Phue\Tests\Support\UsesIsolatedDatabase;
use ReflectionClass;

/**
 * Model stub with soft deletes enabled.
 *
 * Uses the #[SoftDelete] attribute with default column 'deleted_at'.
 */
#[SoftDelete]
class SoftDeleteUser extends BaseActiveRecord
{
    protected string $table = 'soft_delete_users';
}

/**
 * Model stub with custom soft delete column name.
 */
#[SoftDelete(column: 'archived_at')]
class SoftDeletePost extends BaseActiveRecord
{
    protected string $table = 'soft_delete_posts';
}

/**
 * Model stub WITHOUT soft deletes (control).
 */
class HardDeleteUser extends BaseActiveRecord
{
    protected string $table = 'soft_delete_users';
}

/**
 * Unit tests for Soft Delete functionality.
 *
 * Tests cover: soft delete on delete(), restore(), forceDelete(), isTrashed(),
 * global scope on queries, withTrashed(), onlyTrashed(), custom column names,
 * and that models without the attribute are unaffected.
 *
 * @package Phue\Tests\Core\Database
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class SoftDeleteTest extends TestCase
{
    use UsesIsolatedDatabase;

    private \Phue\Core\Database\Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->isolatedDatabase();

        // Table for SoftDeleteUser / HardDeleteUser
        $this->db->execute('
            CREATE TABLE soft_delete_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                deleted_at DATETIME NULL
            )
        ');

        // Table for SoftDeletePost (custom column)
        $this->db->execute('
            CREATE TABLE soft_delete_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                archived_at DATETIME NULL
            )
        ');
    }

    /**
     * Test that delete() performs soft delete (sets deleted_at) instead of hard delete.
     */
    public function testSoftDeleteSetsDeletedAt(): void
    {
        $user = SoftDeleteUser::create([
            'name' => 'Makarios',
            'email' => 'makarios@example.com',
        ]);

        // Verify model is active
        $this->assertTrue($user->isPersisted());
        $this->assertNull($user->deleted_at);
        $this->assertFalse($user->isTrashed());

        $result = $user->delete();
        $this->assertTrue($result);

        // Model should be marked as not existing (soft deleted)
        $this->assertFalse($user->isPersisted());
        $this->assertNotNull($user->deleted_at);
        $this->assertTrue($user->isTrashed());

        // Row should still exist in database with deleted_at set
        $row = $this->db->query('SELECT * FROM soft_delete_users WHERE id = ?', [$user->id])->fetch();
        $this->assertNotNull($row);
        $this->assertNotNull($row['deleted_at']);
    }

    /**
     * Test that find() excludes soft-deleted records by default (global scope).
     */
    public function testFindExcludesSoftDeleted(): void
    {
        $user = SoftDeleteUser::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
        ]);
        $user->delete();

        $found = SoftDeleteUser::find($user->id);
        $this->assertNull($found);

        $all = SoftDeleteUser::all();
        $this->assertCount(0, $all);

        $query = SoftDeleteUser::where('email', 'alice@example.com')->first();
        $this->assertNull($query);
    }

    /**
     * Test that withTrashed() includes soft-deleted records.
     */
    public function testWithTrashedIncludesSoftDeleted(): void
    {
        $user = SoftDeleteUser::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
        ]);
        $user->delete();

        $found = SoftDeleteUser::withTrashed()->find($user->id);
        $this->assertInstanceOf(SoftDeleteUser::class, $found);
        $this->assertTrue($found->isTrashed());

        $all = SoftDeleteUser::withTrashed()->all();
        $this->assertCount(1, $all);
        $this->assertTrue($all[0]->isTrashed());
    }

    /**
     * Test that onlyTrashed() returns only soft-deleted records.
     */
    public function testOnlyTrashedReturnsOnlyDeleted(): void
    {
        $active = SoftDeleteUser::create([
            'name' => 'Active',
            'email' => 'active@example.com',
        ]);
        $deleted = SoftDeleteUser::create([
            'name' => 'Deleted',
            'email' => 'deleted@example.com',
        ]);
        $deleted->delete();

        $trashed = SoftDeleteUser::onlyTrashed()->get();
        $this->assertCount(1, $trashed);
        $this->assertSame('Deleted', $trashed[0]->name);
        $this->assertTrue($trashed[0]->isTrashed());

        $first = SoftDeleteUser::onlyTrashed()->first();
        $this->assertInstanceOf(SoftDeleteUser::class, $first);
        $this->assertSame('Deleted', $first->name);
    }

    /**
     * Test that forceDelete() permanently removes the row.
     */
    public function testForceDeleteRemovesRow(): void
    {
        $user = SoftDeleteUser::create([
            'name' => 'Charlie',
            'email' => 'charlie@example.com',
        ]);
        $user->delete(); // soft delete first
        $id = $user->id;

        // forceDelete should remove the row entirely
        $this->assertTrue($user->forceDelete());

        $row = $this->db->query('SELECT * FROM soft_delete_users WHERE id = ?', [$id])->fetch();
        $this->assertFalse($row);
    }

    /**
     * Test that restore() sets deleted_at to NULL and marks model as existing.
     */
    public function testRestoreSetsDeletedAtNull(): void
    {
        $user = SoftDeleteUser::create([
            'name' => 'Diana',
            'email' => 'diana@example.com',
        ]);
        $user->delete();

        $this->assertFalse($user->isPersisted());
        $this->assertTrue($user->isTrashed());

        $this->assertTrue($user->restore());

        $this->assertTrue($user->isPersisted());
        $this->assertFalse($user->isTrashed());
        $this->assertNull($user->deleted_at);

        // Should now be findable via normal query
        $found = SoftDeleteUser::find($user->id);
        $this->assertInstanceOf(SoftDeleteUser::class, $found);
        $this->assertSame('Diana', $found->name);
    }

    /**
     * Test isTrashed() returns correct state.
     */
    public function testIsTrashedReturnsCorrectState(): void
    {
        $user = new SoftDeleteUser(['name' => 'Test', 'email' => 'test@example.com']);
        $this->assertFalse($user->isTrashed()); // not persisted

        $user->save();
        $this->assertFalse($user->isTrashed()); // active

        $user->delete();
        $this->assertTrue($user->isTrashed()); // soft deleted

        $user->restore();
        $this->assertFalse($user->isTrashed()); // restored
    }

    /**
     * Test that models without #[SoftDelete] attribute are unaffected.
     */
    public function testModelWithoutAttributeUnaffected(): void
    {
        $user = HardDeleteUser::create([
            'name' => 'Eve',
            'email' => 'eve@example.com',
        ]);

        // HardDeleteUser has no #[SoftDelete] attribute
        $this->assertFalse($user->hasSoftDelete());
        $this->assertNull($user->getSoftDeleteColumn());

        // delete() should hard delete
        $this->assertTrue($user->delete());

        $found = HardDeleteUser::find($user->id);
        $this->assertNull($found);

        // Row should be gone
        $row = $this->db->query('SELECT * FROM soft_delete_users WHERE id = ?', [$user->id])->fetch();
        $this->assertFalse($row);
    }

    /**
     * Test bulk soft delete via update query.
     */
    public function testBulkUpdateSoftDelete(): void
    {
        SoftDeleteUser::create(['name' => 'User1', 'email' => 'u1@example.com']);
        SoftDeleteUser::create(['name' => 'User2', 'email' => 'u2@example.com']);
        SoftDeleteUser::create(['name' => 'User3', 'email' => 'u3@example.com']);

        // Soft delete all users via query builder
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $affected = SoftDeleteUser::query()
            ->whereIn('email', ['u1@example.com', 'u2@example.com'])
            ->update(['deleted_at' => $now]);

        $this->assertSame(2, $affected);

        // Only u3 should be visible
        $visible = SoftDeleteUser::all();
        $this->assertCount(1, $visible);
        $this->assertSame('User3', $visible[0]->name);

        // withTrashed should show all 3
        $all = SoftDeleteUser::withTrashed()->all();
        $this->assertCount(3, $all);
    }

    /**
     * Test custom soft delete column name.
     */
    public function testCustomColumnName(): void
    {
        $post = SoftDeletePost::create(['title' => 'My Post']);
        $this->assertNull($post->archived_at);
        $this->assertFalse($post->isTrashed());

        $this->assertTrue($post->delete());

        $this->assertNotNull($post->archived_at);
        $this->assertTrue($post->isTrashed());

        // find should exclude it
        $found = SoftDeletePost::find($post->id);
        $this->assertNull($found);

        // withTrashed should include it
        $found = SoftDeletePost::withTrashed()->find($post->id);
        $this->assertInstanceOf(SoftDeletePost::class, $found);

        // restore should work
        $this->assertTrue($post->restore());
        $this->assertNull($post->archived_at);
        $this->assertFalse($post->isTrashed());
    }

    /**
     * Test that save() works on restored model.
     */
    public function testSaveOnRestoredModelWorks(): void
    {
        $user = SoftDeleteUser::create([
            'name' => 'Frank',
            'email' => 'frank@example.com',
        ]);
        $originalName = $user->name;

        $user->delete();
        $user->restore();

        // Modify and save
        $user->name = 'Frank Updated';
        $this->assertTrue($user->save());

        $found = SoftDeleteUser::find($user->id);
        $this->assertSame('Frank Updated', $found->name);
    }

    /**
     * Test delete() returns false for non-persisted model.
     */
    public function testDeleteOnNonExistentReturnsFalse(): void
    {
        $user = new SoftDeleteUser(['name' => 'Ghost', 'email' => 'ghost@example.com']);

        $this->assertFalse($user->delete());
        $this->assertFalse($user->isPersisted());
    }

    /**
     * Test restore() returns false for non-soft-delete model.
     */
    public function testRestoreOnNonSoftDeleteModelReturnsFalse(): void
    {
        $user = HardDeleteUser::create([
            'name' => 'Test',
            'email' => 'test@example.com',
        ]);

        $this->assertFalse($user->restore());
    }

    /**
     * Test restore() returns false for already active model.
     */
    public function testRestoreOnActiveModelReturnsFalse(): void
    {
        $user = SoftDeleteUser::create([
            'name' => 'Active',
            'email' => 'active@example.com',
        ]);

        $this->assertFalse($user->restore());
    }

    /**
     * Test forceDelete() returns false for non-persisted model.
     */
    public function testForceDeleteOnNonExistentReturnsFalse(): void
    {
        $user = new SoftDeleteUser(['name' => 'Ghost', 'email' => 'ghost@example.com']);

        $this->assertFalse($user->forceDelete());
    }

    /**
     * Test static query forwarding with withTrashed/onlyTrashed.
     */
    public function testStaticWithTrashedOnlyTrashed(): void
    {
        $user = SoftDeleteUser::create(['name' => 'Static', 'email' => 'static@example.com']);
        $user->delete();

        // Static forwarding
        $found = SoftDeleteUser::withTrashed()->find($user->id);
        $this->assertInstanceOf(SoftDeleteUser::class, $found);

        $trashed = SoftDeleteUser::onlyTrashed()->get();
        $this->assertCount(1, $trashed);
    }

    /**
     * Test that count() respects soft delete scope.
     */
    public function testCountRespectsSoftDeleteScope(): void
    {
        SoftDeleteUser::create(['name' => 'A', 'email' => 'a@example.com']);
        SoftDeleteUser::create(['name' => 'B', 'email' => 'b@example.com']);
        $c = SoftDeleteUser::create(['name' => 'C', 'email' => 'c@example.com']);
        $c->delete();

        $this->assertSame(2, SoftDeleteUser::count());
        $this->assertSame(3, SoftDeleteUser::withTrashed()->count());
        $this->assertSame(1, SoftDeleteUser::onlyTrashed()->count());
    }

    /**
     * Test that query chaining works with soft delete scope.
     */
    public function testQueryChainingWithSoftDelete(): void
    {
        SoftDeleteUser::create(['name' => 'Chain1', 'email' => 'c1@example.com']);
        SoftDeleteUser::create(['name' => 'Chain2', 'email' => 'c2@example.com']);
        $deleted = SoftDeleteUser::create(['name' => 'Chain3', 'email' => 'c3@example.com']);
        $deleted->delete();

        // Chained where + get
        $results = SoftDeleteUser::where('name', '!=', 'Chain1')->get();
        $this->assertCount(1, $results);
        $this->assertSame('Chain2', $results[0]->name);

        // Chained where + withTrashed
        $results = SoftDeleteUser::withTrashed()->where('name', '!=', 'Chain1')->get();
        $this->assertCount(2, $results);

        // Chained where + onlyTrashed
        $results = SoftDeleteUser::onlyTrashed()->where('name', 'Chain3')->get();
        $this->assertCount(1, $results);
    }
}