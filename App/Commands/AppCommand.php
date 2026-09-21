<?php

declare(strict_types=1);

namespace App\Commands;

use Phue\Core\Console\BaseCommand;
use App\Models\User;

/**
 * AppCommand - Example command with sub-commands.
 *
 * Usage:
 *   phue app:setup
 *   phue app:createAdmin
 *   phue app         # shows help
 */
final class AppCommand extends BaseCommand
{
    /**
     * Gets the group this command belongs to.
     *
     * @return string Command group name.
     */
    public function getGroup(): string
    {
        return 'App';
    }

    /**
     * Retrieves command identifier name.
     */
    public function getName(): string
    {
        return 'app';
    }

    /**
     * Retrieves command description.
     */
    public function getDescription(): string
    {
        return 'The main App controller command for CLI.';
    }

    /**
     * Executes the 'setup' sub-command.
     *
     * @description Setup initialy system users, roles, permissions
     */
    public function setup(array $args, $out, $err): int
    {
        return $this->createAdmin($args, $out, $err) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Executes the 'createAdmin' sub-command.
     *
     * @description Creates an admin user.
     */
    public function createAdmin(array $args, $out, $err): int
    {
        $basePath = dirname(__DIR__, 2);
        $config = new \Phue\Core\Config($basePath . '/config');
        $driver = (string) $config->get('database.default', 'sqlite');
        $dbConfig = (array) $config->get("database.connections.{$driver}", []);
        $db = new \Phue\Core\Database\Connection($dbConfig);
        \Phue\Core\Database\ActiveRecord\BaseActiveRecord::setConnection($db);

        $user = User::query()->where('username', '=', 'admin')->first() ?? new User();
        $user->fill([
            'username' => 'admin',
            'password_hash' => password_hash('password', PASSWORD_BCRYPT),
            'auth_key' => bin2hex(random_bytes(16)),
            'roles' => '["admin"]',
        ]);
        $user->save();
        fwrite($out, "Admin user created/updated successfully! ID: " . $user->getId() . PHP_EOL);
        return self::SUCCESS;
    }
}