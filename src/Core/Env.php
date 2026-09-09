<?php

declare(strict_types=1);

namespace Phue\Core;

/**
 * Utility class for loading, parsing, and retrieving environment variables from .env files.
 *
 * @package Phue\Core
 */
class Env
{
    /**
     * Internal cache of parsed environment variables.
     *
     * @var array<string, mixed>
     */
    protected static array $variables = [];

    /**
     * Indicates whether the .env file has been loaded.
     */
    protected static bool $loaded = false;

    /**
     * Reset the loaded environment variables and state (Used in testing environments).
     *
     * @return void
     */
    public static function reset(): void
    {
        static::$variables = [];
        static::$loaded = false;
    }

    /**
     * Loads and parses the .env file from the given application base path.
     *
     * @param string $basePath Absolute path to application root directory.
     * @return void
     */
    public static function load(string $basePath): void
    {
        if (static::$loaded) {
            return;
        }

        $filePath = rtrim($basePath, '/\\') . '/.env';

        if (file_exists($filePath) && is_readable($filePath)) {
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }

                    if (str_contains($line, '=')) {
                        [$key, $value] = explode('=', $line, 2);
                        $key = trim($key);
                        $value = trim($value);

                        if (!str_starts_with($value, '"') && !str_starts_with($value, "'")) {
                            $value = explode('#', $value)[0];
                            $value = trim($value);
                        }

                        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                            $value = substr($value, 1, -1);
                        }

                        $value = match (strtolower($value)) {
                            'true', '(true)' => true,
                            'false', '(false)' => false,
                            'null', '(null)', 'empty' => null,
                            default => $value,
                        };

                        static::$variables[$key] = $value;
                        $_ENV[$key] = $value;
                        $_SERVER[$key] = $value;
                        putenv("{$key}={$value}");
                    }
                }
            }
        }

        static::$loaded = true;
    }

    /**
     * Retrieves an environment variable value by key with a fallback default.
     *
     * @param string $key Environment variable key.
     * @param mixed $default Fallback value if key is not found.
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, static::$variables)) {
            return static::$variables[$key];
        }

        $envValue = getenv($key);
        if ($envValue !== false) {
            return match (strtolower($envValue)) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'null', '(null)', 'empty' => null,
                default => $envValue,
            };
        }

        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}