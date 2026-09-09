<?php

declare(strict_types=1);

namespace Phue\Core;

/**
 * Retrieves an environment variable value or returns a default value.
 *
 * @param string $key Environment variable key name.
 * @param mixed $default Default value if variable is missing.
 * @return mixed
 */
if (!function_exists('Phue\Core\env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}