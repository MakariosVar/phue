<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Config;
use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;
use Phue\Core\Database\Connection;
use RuntimeException;

/**
 * Abstract Base CLI Command providing shared database connection resolution.
 *
 * @package Phue\Core\Console\Commands
 */
abstract class AbstractDatabaseCommand implements CommandInterface
{
    /**
     * The group this command belongs to.
     *
     * Commands in the same group are listed together in the help output.
     */
    protected string $group = 'Database';

    /**
     * Resolves application database connection directly from environment config.
     *
     * @throws RuntimeException If config directory or database options are missing.
     *
     * @return Connection Active database connection wrapper instance.
     */
    protected function resolveConnection(): Connection
    {
        $basePath = PathResolver::resolveBasePath();
        $configPath = $basePath . '/config';

        if (!is_dir($configPath)) {
            throw new RuntimeException("Config directory missing at: {$configPath}");
        }

        $config = new Config($configPath);

        $defaultDriver = $config->get('database.default');
        if (!is_string($defaultDriver) || empty($defaultDriver)) {
            throw new RuntimeException("Database connection not configured: 'database.default' is missing.");
        }

        $dbConfig = $config->get("database.connections.{$defaultDriver}");
        if (!is_array($dbConfig)) {
            throw new RuntimeException("Database connection not configured: 'database.connections.{$defaultDriver}' is missing or invalid.");
        }

        return new Connection($dbConfig);
    }
}
