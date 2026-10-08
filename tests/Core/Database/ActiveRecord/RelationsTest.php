<?php

namespace Phue\Tests\Core\Database\ActiveRecord;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\ActiveRecord\Relations\HasMany;
use Phue\Core\Database\ActiveRecord\Relations\HasOne;
use Phue\Core\Database\ActiveRecord\Relations\BelongsTo;
use Phue\Tests\Support\UsesIsolatedDatabase;

/**
 * Stub User model with relations.
 */
class User extends BaseActiveRecord
{
    protected string $table = 'users';

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }
}

/**
 * Stub Order model owning a BelongsTo relation.
 */
class Order extends BaseActiveRecord
{
    protected string $table = 'orders';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

/**
 * Stub Profile model for HasOne tests.
 */
class Profile extends BaseActiveRecord
{
    protected string $table = 'profiles';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

/**
 * Tests for ActiveRecord relations: lazy loading, eager loading,
 * chaining, matching and serialization.
 *
 * @package Phue\Tests\Core\Database\ActiveRecord
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class RelationsTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = BaseActiveRecord::getConnection();

        // Drop and recreate tables for test isolation
        $connection->execute('DROP TABLE IF EXISTS orders');
        $connection->execute('DROP TABLE IF EXISTS profiles');
        $connection->execute('DROP TABLE IF EXISTS users');
        
        $connection->execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'
        );
        $connection->execute(
            'CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT)'
        );
        $connection->execute(
            'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, bio TEXT)'
        );
    }

    private function makeUser(string $name, bool $withOrders = false, bool $withProfile = false): User
    {
        $user = User::create(['name' => $name]);

        if ($withOrders) {
            Order::create(['user_id' => $user->id, 'title' => $name . ' order 1']);
            Order::create(['user_id' => $user->id, 'title' => $name . ' order 2']);
        }

        if ($withProfile) {
            Profile::create(['user_id' => $user->id, 'bio' => $name . ' bio']);
        }

        return $user;
    }

    /**
     * Tests lazy loading of a HasMany relation via magic property access.
     */
    public function testHasManyLazyLoadsRelatedModels(): void
    {
        $user = $this->makeUser('Alice', withOrders: true);

        $orders = $user->orders;

        $this->assertIsArray($orders);
        $this->assertCount(2, $orders);
        $this->assertInstanceOf(Order::class, $orders[0]);
        $this->assertSame($user->id, $orders[0]->user_id);
    }

    /**
     * Tests that a HasMany relation returns an empty array when no children exist.
     */
    public function testHasManyLazyReturnsEmptyArrayWhenNone(): void
    {
        $user = $this->makeUser('Bob');

        $this->assertSame([], $user->orders);
    }

    /**
     * Tests lazy loading of a HasOne relation.
     */
    public function testHasOneLazyReturnsSingleModel(): void
    {
        $user = $this->makeUser('Alice', withProfile: true);

        $profile = $user->profile;

        $this->assertInstanceOf(Profile::class, $profile);
        $this->assertSame('Alice bio', $profile->bio);
    }

    /**
     * Tests that HasOne returns null when no related record exists.
     */
    public function testHasOneLazyReturnsNullWhenMissing(): void
    {
        $user = $this->makeUser('Bob');

        $this->assertNull($user->profile);
    }

    /**
     * Tests lazy loading of a BelongsTo relation.
     */
    public function testBelongsToLazyReturnsParent(): void
    {
        $user = $this->makeUser('Alice');
        $order = Order::create(['user_id' => $user->id, 'title' => 'purchase']);

        $found = $order->user;

        $this->assertInstanceOf(User::class, $found);
        $this->assertSame($user->id, $found->id);
    }

    /**
     * Tests that BelongsTo returns null when the foreign key is dangling.
     */
    public function testBelongsToLazyReturnsNullWhenMissing(): void
    {
        $order = Order::newFromBuilder(['id' => 1, 'user_id' => 999, 'title' => 'orphan']);

        $this->assertNull($order->user);
    }

    /**
     * Tests that lazy-loaded results are cached in the relation bag.
     */
    public function testLazyAccessIsCached(): void
    {
        $user = $this->makeUser('Alice', withOrders: true);

        $first = $user->orders;
        $second = $user->orders;

        $this->assertSame($first, $second);
        $this->assertSame($first, $user->getRelation('orders'));
    }

    /**
     * Tests that chaining a relation call preserves the owner foreign key.
     */
    public function testRelationChainingPreservesForeignKey(): void
    {
        $alice = $this->makeUser('Alice');
        $bob   = $this->makeUser('Bob');

        Order::create(['user_id' => $alice->id, 'title' => 'shared order']);
        Order::create(['user_id' => $bob->id, 'title' => 'shared order']);

        $aliceShared = $alice->orders()->where('title', 'shared order')->get();

        $this->assertCount(1, $aliceShared);
        $this->assertSame($alice->id, $aliceShared[0]->user_id);
    }

    /**
     * Tests eager loading of HasMany relations distributes correctly.
     */
    public function testWithEagerLoadsHasMany(): void
    {
        $alice = $this->makeUser('Alice', withOrders: true);
        $bob   = $this->makeUser('Bob', withOrders: true);

        $users = User::with('orders')->orderBy('id')->get();

        $this->assertCount(2, $users);
        $this->assertCount(2, $users[0]->orders);
        $this->assertCount(2, $users[1]->orders);
        $this->assertSame('Alice order 1', $users[0]->orders[0]->title);
        $this->assertSame('Bob order 1', $users[1]->orders[0]->title);
        $this->assertNull($alice->getRelation('orders'));
        $this->assertIsArray($users[0]->getRelation('orders'));
    }

    /**
     * Tests eager loading of BelongsTo relations.
     */
    public function testWithEagerLoadsBelongsTo(): void
    {
        $alice = $this->makeUser('Alice');
        $bob   = $this->makeUser('Bob');

        Order::create(['user_id' => $alice->id, 'title' => 'a']);
        Order::create(['user_id' => $bob->id, 'title' => 'b']);

        $orders = Order::with('user')->orderBy('id')->get();

        $this->assertCount(2, $orders);
        $this->assertInstanceOf(User::class, $orders[0]->user);
        $this->assertSame('Alice', $orders[0]->user->name);
        $this->assertSame('Bob', $orders[1]->user->name);
    }

    /**
     * Tests eager loading of HasOne across multiple parents (no LIMIT 1 leak).
     */
    public function testWithEagerLoadsHasOneForMultipleParents(): void
    {
        $this->makeUser('Alice', withProfile: true);
        $this->makeUser('Bob', withProfile: true);

        $users = User::with('profile')->orderBy('id')->get();

        $this->assertCount(2, $users);
        $this->assertInstanceOf(Profile::class, $users[0]->profile);
        $this->assertSame('Alice bio', $users[0]->profile->bio);
        $this->assertSame('Bob bio', $users[1]->profile->bio);
    }

    /**
     * Tests that a parent without the related record gets null on eager HasOne.
     */
    public function testWithEagerHasOneSetsNullWhenNoMatch(): void
    {
        $this->makeUser('Alice', withProfile: true);
        $this->makeUser('Bob');

        $users = User::with('profile')->orderBy('id')->get();

        $this->assertInstanceOf(Profile::class, $users[0]->profile);
        $this->assertNull($users[1]->profile);
    }

    /**
     * Tests that first() also eager loads with() relations.
     */
    public function testWithOnFirstEagerLoads(): void
    {
        $this->makeUser('Alice', withOrders: true);

        $user = User::with('orders')->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertCount(2, $user->orders);
    }

    /**
     * Tests the load() method populates the relation bag directly.
     */
    public function testLoadMethodPopulatesRelation(): void
    {
        $user = $this->makeUser('Alice', withOrders: true);

        $result = $user->load('orders');

        $this->assertSame($user, $result);
        $this->assertCount(2, $user->getRelation('orders'));
        $this->assertIsArray($user->orders);
    }

    /**
     * Tests that toArray() flattens loaded relations into nested arrays.
     */
    public function testToArrayIncludesLoadedRelations(): void
    {
        $user = $this->makeUser('Alice', withOrders: true, withProfile: true);
        $user->load('orders', 'profile');

        $array = $user->toArray();

        $this->assertIsArray($array['orders']);
        $this->assertCount(2, $array['orders']);
        $this->assertSame('Alice order 1', $array['orders'][0]['title']);
        $this->assertArrayHasKey('profile', $array);
        $this->assertSame('Alice bio', $array['profile']['bio']);
    }

    /**
     * Tests direct getRelation/setRelation round trip.
     */
    public function testGetAndSetRelationRoundtrip(): void
    {
        $user = new User();

        $user->setRelation('orders', []);
        $this->assertSame([], $user->getRelation('orders'));

        $user->setRelation('profile', null);
        $this->assertNull($user->getRelation('profile'));
    }

    /**
     * Tests that unknown relation names in with() are silently ignored.
     */
    public function testUnknownRelationInWithIsIgnored(): void
    {
        $this->makeUser('Alice');

        $users = User::with('nonexistent')->get();

        $this->assertCount(1, $users);
        $this->assertNull($users[0]->getRelation('nonexistent'));
    }

    /**
     * Tests that lazy loading a relation on an unpersisted parent is safe.
     */
    public function testUnpersistedParentLazyLoadsEmpty(): void
    {
        $user = new User(['name' => 'Ghost']);

        $this->assertSame([], $user->orders);
        $this->assertNull($user->profile);
    }

    /**
     * Tests eager loading returns [] for a parent whose child rows were removed.
     */
    public function testWithEagerHasManyEmptyForDeletedChildren(): void
    {
        $user = $this->makeUser('Alice', withOrders: true);

        foreach ($user->orders as $order) {
            $order->delete();
        }

        $users = User::with('orders')->get();

        $this->assertSame([], $users[0]->orders);
    }
}