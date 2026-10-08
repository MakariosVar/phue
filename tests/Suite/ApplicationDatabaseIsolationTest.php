<?php

declare(strict_types=1);

namespace Phue\Tests\Suite;

use Phue\Core\Application;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Tests\Support\ApplicationDatabase;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use Phue\Tests\Support\TestDatabase;
use Phue\Tests\Support\UsesIsolatedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The suite may not touch the developer's database.
 *
 * What went wrong
 * ---------------
 * `Application::bootstrapDatabase()` ends with a process-wide side effect:
 *
 *     BaseActiveRecord::setConnectionResolver(fn() => $container->get(Connection::class));
 *
 * Active Record's connection lives in statics, and PHPUnit runs every test file
 * in one process (phpunit.xml sets no processIsolation). So a test that boots
 * the real application — `new Application('/app')` — rewires the global
 * connection to the repository's MariaDB for every test that runs after it.
 * Seventeen tests then take `BaseActiveRecord::getConnection()`, drop `users`,
 * and recreate it with SQLite syntax:
 *
 *     $db->execute('DROP TABLE IF EXISTS users');
 *     $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, ...)');
 *
 * The DROP lands on the real table. The CREATE then dies with "check the manual
 * that corresponds to your MariaDB server version", so the suite goes red AND
 * the user's data is gone in the same stroke — and the red is reported as a
 * driver bug, in a file that has nothing to do with the one that leaked.
 *
 * Why this needs several tests and not one
 * ----------------------------------------
 * Because "we checked every file once" is not a property, it is a snapshot of
 * today. The tests below fail for different reasons so that no single mistake
 * can restore the old behaviour:
 *
 *   - the ratchets fail when someone adds an unguarded test (prevention, before
 *     the run);
 *   - the tripwire fails when user-space changed anyway (detection, after the
 *     run — and it names what was lost, which is the only clue available when
 *     the offending file was not part of the selection that ran);
 *   - the behaviour tests fail if the guard traits stop doing their job.
 *
 * The ratchets are the ones that matter. The rest is recovery.
 *
 * @package Phue\Tests\Suite
 */
final class ApplicationDatabaseIsolationTest extends TestCase
{
    use RestoresActiveRecordConnection;

    /**
     * Files that manage the connection by hand and restore it themselves.
     *
     * An allowlist, not an exemption by accident: each entry states what makes it
     * safe, so a reader can check the claim instead of taking it on trust. Adding
     * a file here is a decision, and the decision has to name the reason.
     *
     * @var array<string, string>
     */
    private const HAND_ROLLED_GUARDS = [
        // Snapshots both statics by reflection, installs a private ':memory:'
        // SQLite and restores the snapshot in tearDown. It predates
        // UsesIsolatedDatabase and is the reference implementation of it.
        'Core/Database/ActiveRecord/ModelSerialisationLeakTest.php' =>
            'installs its own :memory: connection and restores both statics by reflection',
    ];

    /**
     * A boot of the real application still rewires Active Record process-wide.
     *
     * Pinned as an assertion because it is the reason the guards exist. If a
     * future refactor makes the framework stop touching statics, these guards
     * become dead code — and this test is the signal to delete them rather than
     * keep believing in them.
     */
    public function testBootingTheApplicationReplacesTheGlobalActiveRecordConnection(): void
    {
        TestDatabase::releaseGlobal();

        new Application('/app');

        $this->assertNotSame(
            TestDatabase::shared(),
            BaseActiveRecord::getConnection(),
            'Application::bootstrapDatabase() no longer replaces the global connection. If that is '
            . 'now true by design, the guard traits in tests/Support/ are dead code and should go.'
        );
    }

    /**
     * The restore hook brings the global connection back to the test database.
     *
     * Invokes the trait's hooks directly instead of through a nested TestCase:
     * those two methods are exactly what PHPUnit's #[Before] / #[After]
     * attributes call, so the sequence below is the one the runner performs.
     */
    public function testTheRestoreHookBringsTheGlobalConnectionBackToTheTestDatabase(): void
    {
        $guard = new class {
            use RestoresActiveRecordConnection;
        };

        $guard->restoreDatabaseBeforeTest();

        new Application('/app');

        $this->assertNotSame(
            TestDatabase::shared(),
            BaseActiveRecord::getConnection(),
            'Precondition: the application boot must have taken over the global connection, or this '
            . 'test would pass for the wrong reason.'
        );

        $guard->restoreDatabaseAfterTest();

        $this->assertSame(
            TestDatabase::shared(),
            BaseActiveRecord::getConnection(),
            'The #[After] hook must leave Active Record on the shared test database.'
        );
    }

    /**
     * Isolation at construction time, for the window teardown cannot cover.
     *
     * Restoring only helps once the test ends. Between `new Application('/app')`
     * returning and the test ending, the container and Active Record both hand
     * out the application's own connection — so any code under test that
     * touches the database does so against the developer's data. The resolver
     * bootstrapDatabase() installs is lazy, which is what makes that window
     * closable: re-binding the singleton rewrites what it will return.
     */
    public function testIsolatingAnApplicationRewiresItsOwnConnectionBeforeItIsUsed(): void
    {
        $app = new Application('/app');

        $this->assertNotSame(
            TestDatabase::shared(),
            $app->getContainer()->get(Connection::class),
            'Precondition: an un-isolated application resolves its own database from config/.'
        );

        $isolated = TestDatabase::isolateApplication($app);

        $this->assertSame($isolated, TestDatabase::shared());
        $this->assertSame(
            $isolated,
            $app->getContainer()->get(Connection::class),
            'The container must hand out the test database after isolation.'
        );
        $this->assertSame(
            $isolated,
            $app->getContainer()->get('db'),
            "The 'db' alias must not be a way around the isolation."
        );
        $this->assertSame(
            $isolated,
            BaseActiveRecord::getConnection(),
            'Active Record must hand out the test database after isolation.'
        );
    }

    /**
     * A test that owns its database cannot be aimed at someone else's.
     *
     * The mid-suite scenario in one method, in the order it actually happens: a
     * previous file leaked, then this test takes ownership, then DDL runs.
     */
    public function testAnOwnedDatabaseSurvivesALeakFromAnotherTest(): void
    {
        // 1. Whatever a previous file did to the statics: an application boot.
        BaseActiveRecord::setConnectionResolver(
            static fn (): Connection => new Connection([
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'phue_sql',
                'username' => 'root',
                'password' => '123',
            ])
        );

        $this->assertFalse(
            TestDatabase::globalIsTestScoped(),
            'Precondition: the injected leak must actually be pointing at user-space, or this test '
            . 'would pass for the wrong reason.'
        );

        // 2. This test takes ownership, the way PHPUnit's #[Before] does it.
        $owner = new class {
            use UsesIsolatedDatabase;

            public function database(): Connection
            {
                return $this->isolatedDatabase();
            }
        };

        $owner->isolateDatabaseBeforeTest();

        $this->assertTrue(
            TestDatabase::globalIsTestScoped(),
            'Taking ownership must neutralise a leak inherited from another file. This is the case '
            . 'that destroyed the users table: the boot happened in a file nobody was looking at.'
        );

        $owned = $owner->database();

        $owned->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $owned->execute('DROP TABLE users');

        $this->assertSame(
            0,
            (int) $owned
                ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'")
                ->fetchColumn(),
            'The DROP must have hit the owned database.'
        );

        $owner->isolateDatabaseAfterTest();

        $this->assertSame(
            TestDatabase::shared(),
            BaseActiveRecord::getConnection(),
            'After the test, the suite is back on its shared database.'
        );
    }

    /**
     * The tripwire: user-space is byte-identical to how the run found it.
     *
     * Skipped, never failed, when the application database cannot be reached —
     * CI without a database service, a contributor who only uses SQLite. A guard
     * that accuses a database it never opened is worse than no guard.
     */
    public function testTheApplicationDatabaseIsUnchangedByThisTestRun(): void
    {
        $baseline = TestDatabase::applicationBaseline();

        if ($baseline === null) {
            $this->markTestSkipped(
                'No application database at ' . ApplicationDatabase::describe()
                . ' — there is nothing to compare against.'
            );
        }

        $current = ApplicationDatabase::fingerprint();

        $this->assertNotNull(
            $current,
            'The application database was reachable when this run started and is not reachable now. '
            . 'Something in the suite stopped the server or renamed the database.'
        );

        $this->assertSame(
            $baseline,
            $current,
            'The application database at ' . ApplicationDatabase::describe() . ' changed during this '
            . 'test run. Something in tests/ is writing to the developer\'s data — find the test that '
            . 'reaches for BaseActiveRecord::getConnection(), or boots an Application, without one of '
            . 'the guard traits in tests/Support/.'
        );
    }

    /**
     * Ratchet, trigger side: booting an application requires a guard.
     *
     * A boot is what leaks. Either trait satisfies the rule, because either one
     * leaves the global connection somewhere this suite owns; which one is right
     * depends on whether the file writes as well as boots.
     *
     * Text-based, like the other meta-tests in this directory: the property
     * under test is what the suite CLAIMS about itself, so reading the source is
     * the honest mechanism rather than a shortcut around the autoloader.
     */
    public function testEveryTestThatBootsAnApplicationCarriesAGuardTrait(): void
    {
        $offenders = [];

        foreach (self::testFiles() as $path) {
            $source = (string) file_get_contents($path);

            // Console\Application is a different class in a different namespace;
            // it never reaches bootstrapDatabase().
            if (preg_match('/^use Phue\\\\Core\\\\Application;$/m', $source) !== 1) {
                continue;
            }

            if (preg_match('/new Application\s*\(/', $source) !== 1) {
                continue;
            }

            if (self::hasGuard($source)) {
                continue;
            }

            $offenders[] = self::relative($path);
        }

        $this->assertSame(
            [],
            $offenders,
            "These tests boot an Application without a guard trait. The constructor rewires the "
            . "process-wide Active Record connection, so every test that runs afterwards inherits a "
            . "database this suite does not own. Add `use RestoresActiveRecordConnection;` (plus "
            . "\$this->isolateApplicationDatabase(\$app) immediately after the boot when the base path "
            . "is the real application), or `use UsesIsolatedDatabase;` when the test issues DDL.\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * Ratchet, weapon side: writing on the ambient connection requires ownership.
     *
     * The boot is the trigger; a `DROP TABLE` on whatever connection it left
     * behind is the weapon. Guarding only the trigger would leave the weapon
     * aimed at user-space the next time some future file forgets.
     */
    public function testEveryTestThatWritesOnTheAmbientConnectionOwnsItsDatabase(): void
    {
        $offenders = [];

        foreach (self::testFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (!str_contains($source, 'BaseActiveRecord::getConnection()')) {
                continue;
            }

            $relative = self::relative($path);

            if (self::hasGuard($source) || isset(self::HAND_ROLLED_GUARDS[$relative])) {
                continue;
            }

            $offenders[] = $relative;
        }

        $this->assertSame(
            [],
            $offenders,
            "These tests take BaseActiveRecord::getConnection() — the process-wide connection — and "
            . "issue DDL against it. While that connection is the shared SQLite the suite is fine; the "
            . "moment another file leaves a resolver behind, the DROP lands in the developer's MariaDB. "
            . "Use `use UsesIsolatedDatabase;` so the database belongs to the test, or list the file in "
            . "HAND_ROLLED_GUARDS with the reason it is safe.\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * Every hand-rolled guard still does what its entry claims.
     *
     * An allowlist of file paths decays silently: the file gets refactored, the
     * protection goes with it, and the entry keeps passing because it only ever
     * checked that the path exists. This checks the substance.
     */
    public function testEveryHandRolledGuardStillRestoresTheConnectionItTook(): void
    {
        foreach (self::HAND_ROLLED_GUARDS as $relative => $reason) {
            $path = dirname(__DIR__) . '/' . $relative;

            $this->assertFileExists($path, $relative . ' is allowlisted but does not exist.');

            $source = (string) file_get_contents($path);

            $this->assertStringContainsString(
                'setConnectionResolver',
                $source,
                $relative . ' is allowlisted as installing its own resolver, but no longer does.'
            );

            $this->assertMatchesRegularExpression(
                '/function tearDown|#[\\\\]After/',
                $source,
                $relative . ' is allowlisted as restoring what it took, but has no teardown to do it in.'
            );
        }
    }

/**
 * Does this file actually USE one of the guard traits?
     *
     * Matching the trait *use statement* rather than the bare name, because the
     * bare name also appears on the import line — and an import that no class
     * uses satisfies a `str_contains()` check while protecting nothing. That is
     * not a hypothetical: removing `use RestoresActiveRecordConnection;` from a
     * class body and leaving the import behind is the shape a careless revert
     * takes, and a ratchet that cannot see it is decoration.
     */
    private static function hasGuard(string $source): bool
    {
        return preg_match(
            '/^[ \t]*use (?:RestoresActiveRecordConnection|UsesIsolatedDatabase);[ \t]*$/m',
            $source
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private static function testFiles(): array
    {
        $root = dirname(__DIR__);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), 'Test.php')) {
                $real = $file->getRealPath();

                if ($real !== false) {
                    $files[] = $real;
                }
            }
        }

        sort($files);

        return $files;
    }

    private static function relative(string $path): string
    {
        return (string) preg_replace(
            '#^' . preg_quote(dirname(__DIR__), '#') . '/#',
            '',
            $path
        );
    }
}