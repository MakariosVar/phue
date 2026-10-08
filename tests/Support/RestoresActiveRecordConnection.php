<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

use Phue\Core\Application;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Restricts the global ActiveRecord connection to the lifetime of one test.
 *
 * The defect
 * ----------
 * BaseActiveRecord keeps its connection in two static properties, and the
 * framework writes to them from inside Application::bootstrapDatabase():
 *
 *     BaseActiveRecord::setConnectionResolver(fn() => $container->get(Connection::class));
 *
 * That call has process-wide effect. Any test that constructs a
 * `new Application($realBasePath)` therefore permanently redirects the global
 * connection to whatever the application's .env resolves to — MariaDB — and
 * nothing puts it back.
 *
 * The damage is invisible in a single-file run and severe in a suite, because
 * the state leaks across FILES rather than across tests in a class:
 *
 *   - later tests that issue SQLite DDL (`INTEGER PRIMARY KEY AUTOINCREMENT`)
 *     fail with "check the manual that corresponds to your MariaDB server
 *     version", which reads like a driver bug and is not one;
 *   - later tests that issue `DROP TABLE users` destroy the real application
 *     table in MariaDB.
 *
 * Twelve test classes booting the real application base path were enough to
 * take 46 unrelated tests red across four files, while every one of those 46
 * passed on its own. Run order therefore decides whether the suite is green,
 * which makes the suite useless as a gate.
 *
 * Why #[Before] / #[After] instead of setUp() / tearDown()
 * ---------------------------------------------------------
 * The affected classes already define their own setUp() and tearDown(). Trait
 * methods with those names are silently overridden by the class, which is
 * exactly the kind of quiet no-op that let this survive. PHPUnit's hook
 * attributes are additive, so there is nothing to override and no parent call
 * to forget.
 *
 * What it does on the way in, not only on the way out
 * --------------------------------------------------
 * Restoring in teardown is half the contract. The other half is the #[Before]
 * hook: it collapses whatever state the previous file left behind, so a test
 * that boots an application cannot inherit an earlier application's resolver
 * and destroy a database it never intended to touch. The trait also does NOT
 * restore the snapshotted values verbatim — a snapshot taken from a leaked
 * state points at user-space, and re-installing it would propagate the leak to
 * the next file. The suite has one correct resting state; see
 * TestDatabase::releaseGlobal().
 *
 * What this does NOT do
 * ---------------------
 * It does not stop a test from choosing its own connection. Several tests
 * deliberately swap in a private in-memory SQLite; that still works, because
 * the restore puts back whatever was in place before this test started.
 *
 * For tests that issue DDL, prefer UsesIsolatedDatabase. This trait protects
 * the files that BOOT an application; that one protects the files that WRITE,
 * and it protects them even when someone forgets this trait.
 *
 * @package Phue\Tests\Support
 */
trait RestoresActiveRecordConnection
{
    #[Before]
    public function restoreDatabaseBeforeTest(): void
    {
        TestDatabase::releaseGlobal();
    }

    #[After]
    public function restoreDatabaseAfterTest(): void
    {
        TestDatabase::releaseGlobal();
    }

    /**
     * Point a freshly constructed Application at the test database.
     *
     * Call this immediately after `new Application($basePath)` in every test
     * that boots the real application. Restoring in teardown cannot help while
     * the test is running: between the constructor returning and the test
     * ending, Active Record and the container both hand out the application's
     * own database, and any code under test that touches the database does so
     * against the developer's data.
     *
     * The container's Connection singleton is what makes this work.
     * bootstrapDatabase() installs a lazy resolver — `fn() => $container->get(...)`
     * — so re-binding the singleton before the first get() rewrites what Active
     * Record will return for the rest of the test.
     */
    protected function isolateApplicationDatabase(Application $application): void
    {
        TestDatabase::isolateApplication($application);
    }
}