<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

use PDO;
use PDOException;

/**
 * Read-only probe of the developer's real application database.
 *
 * Why this exists
 * ---------------
 * Tests/Support/TestDatabase.php prevents the suite from writing to user-space.
 * Prevention, though, is only as good as the list of tests that opted in, and
 * the list has to be maintained by hand. This class is the other half: it
 * notices when user-space changed anyway.
 *
 * That matters because the failure this guards against is silent by nature.
 * A test that boots the real application points Active Record at MariaDB and
 * says nothing about it; the next test that drops a table takes the real one
 * with it. When the suite is run on a subset — which is how it is worked on
 * daily — the damage depends on which files were included, so the symptom is
 * an intermittent, unexplainable missing table rather than a red test.
 *
 * What "changed" means here
 * -------------------------
 * Every table's name, its CREATE statement and its row count. Schema alone
 * would miss a DELETE and a TRUNCATE; row counts alone would miss a dropped
 * column. Together they cover the two ways a test damages a database without
 * touching the other.
 *
 * Why it parses .env itself instead of using Phue\Core\Env
 * ------------------------------------------------------
 * `Env::load()` is process-global and caches into a static, and the whole
 * subject of this file is process-global state that must not be disturbed by a
 * probe. Loading the application's .env here would set exactly the variables
 * that cause the leak. The parser below reads the file and stops there.
 *
 * @package Phue\Tests\Support
 */
final class ApplicationDatabase
{
    /**
     * Fingerprint the application's database, or null when it cannot be reached.
     *
     * Null is the honest answer for every environment that has no MariaDB
     * running — CI without a database service, a contributor who only uses
     * SQLite. The tripwire then has nothing to compare and stays silent, which
     * is the correct behaviour: it cannot accuse a database it never saw.
     */
    public static function fingerprint(?string $basePath = null): ?string
    {
        $pdo = self::connect($basePath ?? self::basePath());

        if ($pdo === null) {
            return null;
        }

        try {
            $state = [];

            $tables = $pdo
                ->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')
                ?->fetchAll(PDO::FETCH_NUM) ?? [];

            foreach ($tables as $table) {
                // Column 0 is the name, column 1 the type — and the WHERE above
                // made every row say BASE TABLE, so the obvious-looking index 1
                // yields the literal string 'BASE TABLE' as a table name.
                $name = (string) $table[0];

                $quoted = '`' . str_replace('`', '``', $name) . '`';

                $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)?->fetch(PDO::FETCH_NUM)[1] ?? '';

                $count = $pdo->query('SELECT COUNT(*) FROM ' . $quoted)?->fetchColumn();

                $state[$name] = md5($create) . ':' . (string) $count;
            }

            ksort($state);

            return md5((string) json_encode($state));
        } catch (PDOException) {
            // A dropped table between SHOW FULL TABLES and SELECT COUNT(*) means
            // something in this process is writing to user-space. That is the
            // signal we are here for, so report a fingerprint that will not match
            // rather than swallowing it.
            return 'unstable:' . md5((string) microtime(true));
        }
    }

    /**
     * A human-readable description of the target, for failure messages.
     */
    public static function describe(?string $basePath = null): string
    {
        $basePath = $basePath ?? self::basePath();
        $env = self::dotEnv($basePath);

        return sprintf(
            '%s@%s:%s/%s (from %s)',
            $env['DB_USERNAME'] ?? 'root',
            $env['DB_HOST'] ?? '127.0.0.1',
            $env['DB_PORT'] ?? '3306',
            $env['DB_DATABASE'] ?? 'phue',
            $basePath . '/.env'
        );
    }

    /**
     * Open a read-only connection to the application database, or null.
     *
     * Everything here is best-effort and swallows its own errors: this runs in
     * tests/bootstrap.php, before a single assertion, and a probe that turns a
     * missing database into a fatal error would take the suite with it.
     */
    private static function connect(string $basePath): ?PDO
    {
        if (!is_file($basePath . '/.env')) {
            return null;
        }

        $env = self::dotEnv($basePath);

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            $env['DB_HOST'] ?? '127.0.0.1',
            $env['DB_PORT'] ?? '3306',
            $env['DB_DATABASE'] ?? 'phue'
        );

        try {
            return new PDO(
                $dsn,
                $env['DB_USERNAME'] ?? 'root',
                $env['DB_PASSWORD'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2,
                ]
            );
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * Parse a .env file into a plain array.
     *
     * Deliberately a parser and not Phue\Core\Env: see the class docblock.
     *
     * @return array<string, string>
     */
    private static function dotEnv(string $basePath): array
    {
        $file = $basePath . '/.env';

        if (!is_readable($file)) {
            return [];
        }

        $values = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (!str_starts_with($value, '"') && !str_starts_with($value, "'")) {
                $value = trim(explode('#', $value)[0]);
            }

            if (strlen($value) > 1) {
                $first = $value[0];

                if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                    $value = substr($value, 1, -1);
                }
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * The repository root, which is also the application's base path in Docker.
     */
    private static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }
}