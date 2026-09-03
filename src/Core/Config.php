<?php

declare(strict_types=1);

namespace Phue\Core;

/**
 * Configuration registry and environment settings manager.
 *
 * @package Phue\Core
 */
class Config
{
    /**
     * Repository storing all loaded config key-value pairs.
     *
     * @var array<string, mixed>
     */
    private array $items = [];

    /**
     * Load configuration PHP files from the specified directory.
     *
     * @param string $configPath Directory containing PHP config array files.
     */
    public function __construct(string $configPath)
    {
        if (is_dir($configPath)) {
            $this->loadFromPath($configPath);
        }
    }

    /**
     * Scan directory and merge configuration files.
     *
     * @param string $path
     * @return void
     */
    private function loadFromPath(string $path): void
    {
        $files = glob(rtrim($path, '/') . '/*.php');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $value = require $file;

            if (is_array($value)) {
                $this->items[$key] = $value;
            }
        }
    }

    /**
     * Retrieve a configuration item using dot notation syntax.
     *
     * @param string $key Dot-notation key (e.g. 'database.driver').
     * @param mixed $default Fallback value if key is not found.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $array = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }

    /**
     * Check whether a configuration key exists.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Set/override a configuration option dynamically.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $array = &$this->items;

        while (count($keys) > 1) {
            $key = array_shift($keys);

            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;
    }
}