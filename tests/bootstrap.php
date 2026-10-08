<?php

/**
 * Test bootstrap file - sets up shared database connection for all database tests.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Phue\Core\Database\Connection;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Tests\Support\ApplicationDatabase;
use Phue\Tests\Support\TestDatabase;

// Create a single file-based SQLite database that persists across all test classes
// Using a file in /tmp ensures it's cleaned up on reboot and shared across tests
$testDbPath = '/tmp/phue_test_' . getmypid() . '.sqlite';

// Remove any existing test database
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

// Create shared connection
$sharedConnection = new Connection([
    'driver'   => 'sqlite',
    'database' => $testDbPath,
]);

// Set as global connection for BaseActiveRecord
BaseActiveRecord::setConnection($sharedConnection);

// Hand the connection to tests/Support/TestDatabase.php, which owns the invariant
// that Active Record may only ever point at a database this suite created.
TestDatabase::registerShared($sharedConnection, $testDbPath);

// Fingerprint the developer's real application database BEFORE the first test
// runs. Tests/Suite/ApplicationDatabaseIsolationTest.php compares it against the
// state at the end of the run, which is the only thing that turns "the users
// table disappeared again" into a named offender. Null when the database is not
// reachable, and then the comparison has nothing to say.
TestDatabase::captureApplicationBaseline(ApplicationDatabase::fingerprint());

// Register shutdown function to clean up
register_shutdown_function(function () use ($testDbPath) {
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
});