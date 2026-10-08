<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

use Phue\Core\Application;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;

/**
 * The one place that knows what "the test database" means.
 *
 * The defect this exists to prevent
 * --------------------------------
 * `Application::bootstrapDatabase()` ends with:
 *
 *     BaseActiveRecord::setConnectionResolver(fn(): Connection => $this->container->get(Connection::class));
 *
 * Both halves of `BaseActiveRecord`'s state — the resolver and the stored
 * connection — are static, and PHPUnit runs every test file in ONE process
 * (phpunit.xml sets no processIsolation). So a test that boots the real
 * application (`new Application('/app')`) rewires the *global* Active Record
 * connection to whatever the repository's .env resolves to — MariaDB — and it
 * stays rewired for every test that runs afterwards, in whatever order.
 *
 * The visible damage is not subtle. A later test that does
 *
 *     $db = BaseActiveRecord::getConnection();
 *     $db->execute('DROP TABLE IF EXISTS users');
 *
 * drops the developer's real `users` table. Then the SQLite-flavoured CREATE
 * that follows fails with "check the manual that corresponds to your MariaDB
 * server version", so the suite goes red AND user-space is destroyed in the
 * same stroke. That is why this looked intermittent: the damage depends on
 * whether the app-booting file happened to run first.
 *
 * The invariant, stated once
 * --------------------------
 * A test may reach the application's database only if it was built for it.
 * Nothing the suite does implicitly may. Two rules enforce that, and this
 * class is what both rules call:
 *
 *   1. Tests that issue DDL own a private SQLite database
 *      (UsesIsolatedDatabase). Owning the connection makes them order
 *      independent, so an unguarded test elsewhere cannot redirect their DDL.
 *
 *   2. Tests that boot an application put the global connection back
 *      (RestoresActiveRecordConnection). Restoring is the mirror image of the
 *      leak, and it also neutralises a leak that arrived from outside.
 *
 * Why both, and not just (2): (2) only helps if every leaker opts in, which is
 * a promise about the future. (1) makes the destructive tests safe regardless
 * of who else misbehaves. Either alone is a convention; together the second
 * one is a backstop for the first.
 *
 * @package Phue\Tests\Support
 */
final class TestDatabase
{
    /**
     * The suite-wide shared SQLite database, handed over by tests/bootstrap.php.
     *
     * A single instance, not a factory: several tests hold references to it
     * across setUp bodies, and handing out fresh Connection objects would make
     * identity comparisons a lie.
     */
    private static ?Connection $shared = null;

    /**
     * Fingerprint of the real application database, taken before the first test
     * runs. Null when the database is not reachable from this environment, in
     * which case the tripwire has nothing to compare and stays quiet.
     *
     * @var string|null
     */
    private static ?string $applicationBaseline = null;

    /**
     * Take ownership of the connection tests/bootstrap.php created.
     *
     * The path is not stored — bootstrap.php owns the file and unlinks it — but
     * it is checked. If this hand-off ever stops being a throwaway SQLite file,
     * every DDL statement in the suite is aimed at a real database, and finding
     * that out here is the difference between a clear message and an
     * unexplained missing table three tests later.
     */
    public static function registerShared(Connection $connection, string $path): void
    {
        if (self::pathOf($connection) !== $path) {
            throw new \LogicException(sprintf(
                'The suite was handed a connection to "%s" while claiming "%s". Every test that '
                . 'takes BaseActiveRecord::getConnection() would be writing to it.',
                self::pathOf($connection) ?? '(unknown)',
                $path
            ));
        }

        self::$shared = $connection;
    }

    /**
     * The canonical suite-wide test database.
     */
    public static function shared(): Connection
    {
        if (self::$shared === null) {
            throw new \LogicException(
                'The shared test database was never registered. tests/bootstrap.php owns that '
                . 'hand-off; if you are running a test file without the suite bootstrap, this '
                . 'is a harness bug, not a test bug.'
            );
        }

        return self::$shared;
    }

    /**
     * A private SQLite database for one test method, in the system temp dir.
     *
     * File-backed rather than ':memory:' on purpose: an in-memory SQLite dies
     * with its PDO handle, and a driver that reconnects (Connection::reset(),
     * a lost handle, a module re-binding) would take the schema with it. The
     * file is removed in UsesIsolatedDatabase's teardown.
     */
    public static function privateDatabase(string $label): Connection
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '_', $label) ?? 'test';
        $path = sprintf('%s/phue_isolated_%s_%d_%s.sqlite', sys_get_temp_dir(), strtolower($slug), getmypid(), uniqid('', false));

        return new Connection([
            'driver' => 'sqlite',
            'database' => $path,
        ]);
    }

    /**
     * Forget a database created by privateDatabase(), including its file.
     *
     * Best-effort by design: a teardown that throws while cleaning up hides the
     * real failure, which is always the assertion that just ran.
     */
    public static function discard(Connection $connection): void
    {
        $path = self::pathOf($connection);

        if ($path === null || $path === ':memory:' || !is_file($path)) {
            return;
        }

        @unlink($path);
    }

    /**
     * Point Active Record at a connection this suite owns.
     *
     * Both statics, always, in this order. `getConnection()` consults the
     * resolver FIRST and only falls back to the stored connection, so calling
     * `setConnection()` alone is a no-op whenever a resolver survives from an
     * earlier test — which is exactly the situation this class exists for.
     */
    public static function forceGlobal(Connection $connection): void
    {
        BaseActiveRecord::setConnection($connection);
        BaseActiveRecord::setConnectionResolver(static fn (): Connection => $connection);
    }

    /**
     * Restore the canonical post-test state: the shared test database, no resolver.
     *
     * This does NOT restore a previously snapshotted value, and the difference
     * matters. A snapshot taken from a leaked state points at user-space, so
     * restoring it would re-leak on the way out and make the next file's damage
     * depend on the previous file's cleanup. The suite has one correct resting
     * state; everything else is a deviation to be collapsed, not propagated.
     */
    public static function releaseGlobal(): void
    {
        BaseActiveRecord::setConnection(self::shared());

        // setConnectionResolver() is typed \Closure, so there is no API for
        // removing a resolver — only for replacing one. Clearing the property
        // directly is the only way to get back to "no resolver, use the stored
        // connection", and it is also the more honest end state: with no
        // resolver, getConnection() cannot be re-routed by anything that
        // forgets to restore.
        $reflection = new \ReflectionClass(BaseActiveRecord::class);
        $reflection->setStaticPropertyValue('connectionResolver', null);
    }

    /**
     * Redirect an application's own connection at the test database.
     *
     * `bootstrapDatabase()` installs a LAZY resolver
     * (`fn() => $container->get(Connection::class)`) and the container resolves
     * the singleton on first `get()`. Re-binding the singleton before anything
     * asks for it therefore rewrites what Active Record will hand out for the
     * whole remaining life of that Application — including the code under test,
     * which is the part that would otherwise read from (or write to) the real
     * database while a test exercises it.
     *
     * This closes the window that restoring in tearDown cannot: between the
     * Application being constructed and the test ending.
     *
     * @return Connection The connection the application will use from now on.
     */
    public static function isolateApplication(Application $application): Connection
    {
        $connection = self::shared();

        $application->getContainer()->singleton(Connection::class, static fn (): Connection => $connection);
        $application->getContainer()->alias('db', Connection::class);

        self::forceGlobal($connection);

        return $connection;
    }

    /**
     * Is this connection one the suite is allowed to write to?
     *
     * Used by the guards to fail loudly instead of quietly aiming DDL at the
     * application's MariaDB. Deliberately conservative: an unrecognised target
     * is not "probably fine", it is a violation worth stopping on.
     */
    public static function isTestScoped(?Connection $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        try {
            $driver = $connection->getDriver();
        } catch (\Throwable) {
            return false;
        }

        if ($driver->getName() !== 'sqlite') {
            return false;
        }

        $path = self::pathOf($connection);

        if ($path === null) {
            return false;
        }

        if ($path === ':memory:') {
            return true;
        }

        $temp = realpath(sys_get_temp_dir());

        return $temp !== false && str_starts_with($path, $temp);
    }

    /**
     * Is Active Record currently pointed at a database this suite owns?
     */
    public static function globalIsTestScoped(): bool
    {
        try {
            return self::isTestScoped(BaseActiveRecord::getConnection());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The SQLite file behind a connection, or null when it is not SQLite.
     *
     * The driver keeps its config in a protected property and exposes no
     * accessor, so this is reflection. That is acceptable here precisely
     * because it is a guard: reading the private state of a collaborator to
     * prove it is safe is the whole job, and it cannot be fooled by a subclass
     * that overrides getName().
     */
    public static function pathOf(Connection $connection): ?string
    {
        try {
            $driver = $connection->getDriver();
            $property = (new \ReflectionClass($driver))->getProperty('config');

            if (!$property->isPublic() && !$property->isProtected()) {
                $property->setAccessible(true);
            }

            $config = $property->getValue($driver);

            if (!is_array($config)) {
                return null;
            }

            $database = $config['database'] ?? null;

            return is_string($database) ? $database : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Record the state of the real application database, before any test runs.
     */
    public static function captureApplicationBaseline(?string $fingerprint): void
    {
        self::$applicationBaseline = $fingerprint;
    }

    /**
     * The application database fingerprint taken at suite start, or null when
     * the database was not reachable (nothing to compare against).
     */
    public static function applicationBaseline(): ?string
    {
        return self::$applicationBaseline;
    }
}