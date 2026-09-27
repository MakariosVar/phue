<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
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
 * What this does NOT do
 * ---------------------
 * It does not stop a test from choosing its own connection. Several tests
 * deliberately swap in a private in-memory SQLite; that still works, because
 * the restore puts back whatever was in place before this test started.
 *
 * @package Phue\Tests\Support
 */
trait RestoresActiveRecordConnection
{
    private mixed $arSavedResolver = null;

    private mixed $arSavedConnection = null;

    #[Before]
    public function snapshotActiveRecordConnectionState(): void
    {
        $reflection = new \ReflectionClass(BaseActiveRecord::class);

        $this->arSavedResolver = $reflection->getStaticPropertyValue('connectionResolver');
        $this->arSavedConnection = $reflection->getStaticPropertyValue('dbConnection');
    }

    #[After]
    public function restoreActiveRecordConnectionState(): void
    {
        $reflection = new \ReflectionClass(BaseActiveRecord::class);

        // Unconditional. An earlier version of this logic restored only when a
        // guessed bootstrap file path existed; when it did not, dbConnection
        // stayed null and the next getConnection() silently fell through to the
        // .env database — the same class of bug this trait exists to remove.
        $reflection->setStaticPropertyValue('connectionResolver', $this->arSavedResolver);
        $reflection->setStaticPropertyValue('dbConnection', $this->arSavedConnection);
    }
}
