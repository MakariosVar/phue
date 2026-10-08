<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

use Phue\Core\Database\Connection;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Hands a test its own SQLite database, so no other test can redirect its DDL.
 *
 * The problem this solves
 * ----------------------
 * Seventeen test files reach for `BaseActiveRecord::getConnection()` — the
 * ambient, process-wide connection — and then issue DDL against it. That is
 * fine while the ambient connection is the shared SQLite from
 * tests/bootstrap.php, and catastrophic the moment it is not: Active Record
 * stores its connection in statics, `Application::bootstrapDatabase()` rewires
 * those statics from inside the constructor, and PHPUnit runs the whole suite
 * in one process. So `new Application('/app')` in an unrelated file silently
 * repoints every later `DROP TABLE IF EXISTS users` at the developer's MariaDB.
 *
 * The damage then hides behind a syntax error: the DROP succeeds and the
 * SQLite-flavoured CREATE that follows dies with "check the manual that
 * corresponds to your MariaDB server version", which reads like a driver bug.
 * By then the real table is already gone and the run is red for a reason that
 * has nothing to do with the assertion that failed.
 *
 * Why owning the connection is the fix
 * ------------------------------------
 * Because the destructive statement then goes to a database this test created,
 * the order of files stops mattering. There is no list of "well-behaved tests"
 * to keep up to date: a test that owns its database cannot be broken by an
 * unguarded test that runs before it.
 *
 * Both halves of the static state are set, deliberately. `getConnection()`
 * consults the resolver before the stored connection, so `setConnection()`
 * alone is a no-op whenever a resolver survives from an earlier test — which is
 * the exact situation here. tests/Suite/KnownVulnerabilityTest.php documents
 * the same discovery next to the first implementation of this pattern.
 *
 * How to use it
 * -------------
 *     use UsesIsolatedDatabase;
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->db = $this->isolatedDatabase();
 *         $this->db->execute('CREATE TABLE ...');
 *     }
 *
 * A test that keeps its existing `$this->db = BaseActiveRecord::getConnection();`
 * line also works — it now returns the private database — but reading it from
 * here says out loud that the connection is owned, which is the part a reader
 * needs to know.
 *
 * @package Phue\Tests\Support
 */
trait UsesIsolatedDatabase
{
    private ?Connection $isolatedConnection = null;

    /**
     * Give this test a database nobody else can see, before setUp() runs.
     *
     * #[Before] rather than a setUp() helper for the same reason
     * RestoresActiveRecordConnection uses it: the classes that need this already
     * define their own setUp(), and a trait method of that name is silently
     * overridden by the class — a no-op that looks like it ran.
     */
    #[Before]
    public function isolateDatabaseBeforeTest(): void
    {
        $this->isolatedConnection = TestDatabase::privateDatabase(static::class);

        TestDatabase::forceGlobal($this->isolatedConnection);
    }

    /**
     * Hand the suite its shared database back and delete the file.
     */
    #[After]
    public function isolateDatabaseAfterTest(): void
    {
        if ($this->isolatedConnection !== null) {
            TestDatabase::discard($this->isolatedConnection);
            $this->isolatedConnection = null;
        }

        TestDatabase::releaseGlobal();
    }

    /**
     * The private database for the running test.
     */
    protected function isolatedDatabase(): Connection
    {
        if ($this->isolatedConnection === null) {
            // Reachable only if a subclass's own setUp() swallowed the hook, or
            // if something called isolatedDatabase() outside a test. Failing here
            // is better than silently handing back the ambient connection, which
            // is the bug this trait exists to make impossible.
            $this->isolateDatabaseBeforeTest();
        }

        return $this->isolatedConnection;
    }
}