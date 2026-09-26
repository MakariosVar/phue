<?php

/**
 * Test bootstrap file - sets up shared database connection for all database tests.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Phue\Core\Database\Connection;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

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

// Register shutdown function to clean up
register_shutdown_function(function () use ($testDbPath) {
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
});