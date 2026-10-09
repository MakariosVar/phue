<?php

declare(strict_types=1);

namespace Phue\Core;

use Phue\Core\I18n\Translator;

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

/**
 * Translate a message into the active locale.
 *
 * Thin wrapper over the request-scoped Translator, whose active instance the
 * container installs per request and drops on flushScope(). Outside a request
 * — a CLI command, a queue worker before its scope exists, a test that never
 * built an Application — it returns the message unchanged, which is the same
 * answer as a missing translation.
 *
 * Deliberately never throws. A translation problem must not become an outage:
 * the worst outcome of an untranslatable string is a page in English.
 *
 * @param string               $message Source-language text, used as the key.
 * @param array<string, mixed> $params  Values for {placeholder} substitution.
 * @param string|null          $locale  Force a locale; null uses the active one.
 */
if (!function_exists('Phue\Core\t')) {
    function t(string $message, array $params = [], ?string $locale = null): string
    {
        return Translator::active()?->t($message, $params, $locale) ?? $message;
    }
}
