<?php

declare(strict_types=1);

namespace Phue\Core\I18n;

use Phue\Core\ResettableInterface;

/**
 * Turns a message written in the source language into the active language.
 *
 * The message IS the key
 * ----------------------
 * `t('Field \'{field}\' is required')` — the English source doubles as the
 * lookup key, so there is no catalog to keep in sync while writing code and no
 * extraction step to forget. Yii2's bargain, and the price is known: EDITING
 * THE ENGLISH SOURCE BREAKS EVERY TRANSLATION THAT REFERENCED IT. The
 * convention that makes that survivable is in the docblock of
 * supportedRuleList()-style catalogs — treat the source string as a contract.
 *
 * Because the key is the source, a missing translation has an obvious and safe
 * answer: return the message unchanged. A half-translated UI shows English
 * where it has to; it never shows a key, an empty string, or a broken page.
 *
 * Why this is request-scoped
 * --------------------------
 * A long-running worker (Swoole, RoadRunner) serves request after request from
 * ONE process. A translator held as a singleton keeps whatever locale the
 * previous request set, and the next visitor is answered in the previous
 * visitor's language. That is a cross-user data leak wearing a translation bug
 * as a costume. Register this with Container::scoped() and implement
 * ResettableInterface, and flushScope() calls reset() between requests.
 *
 * Catalog layers
 * --------------
 * The framework ships its own; an application adds its own on top. The
 * application's entries win, so an application can override a framework string
 * without patching the framework.
 */
final class Translator implements ResettableInterface
{
    /**
     * The translator serving the request in progress.
     *
     * Exists so `t()` can be called without threading a dependency through
     * every controller and every component. Held only for the lifetime of one
     * request: the container builds it per request and reset() drops the
     * reference, so a stale translator cannot answer for the next visitor.
     */
    private static ?self $active = null;

    /** @var array<string, array<string, string>> Loaded catalogs, keyed by locale. */
    private array $catalogs = [];

    /** @var list<string> Directories searched for <locale>.php, in priority order. */
    private array $paths;

    /** @var list<string> Locales that were asked for and not found anywhere. */
    private array $supported = [];

    private ?string $locale = null;

    /**
     * @param string      $defaultLocale   Locale used when nothing else resolves.
     * @param string|null $appLocalePath   Application catalog directory; lower priority than the framework's own is NOT assumed — the app path is appended last so it wins.
     */
    public function __construct(
        private readonly string $defaultLocale = 'en',
        ?string $appLocalePath = null,
    ) {
        $this->paths = [__DIR__ . '/locales'];

        if ($appLocalePath !== null && $appLocalePath !== '') {
            $this->paths[] = rtrim($appLocalePath, '/');
        }
    }

    // ------------------------------------------------------------- lifecycle

    /**
     * The translator for the request in progress, if one has been installed.
     */
    public static function active(): ?self
    {
        return self::$active;
    }

    /**
     * Install this translator as the one `t()` delegates to.
     */
    public function activate(): void
    {
        self::$active = $this;
    }

    /**
     * Drops request state. Called by Container::flushScope() between requests.
     */
    public function reset(): void
    {
        $this->locale = null;
        $this->catalogs = [];
        $this->supported = [];

        if (self::$active === $this) {
            self::$active = null;
        }
    }

    // ---------------------------------------------------------------- locale

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * The locale in force, falling back to the configured default.
     */
    public function getLocale(): string
    {
        return $this->locale ?? $this->defaultLocale;
    }

    /**
     * Locales that have a catalog somewhere on the search path.
     *
     * @return list<string>
     */
    public function supportedLocales(): array
    {
        if ($this->supported !== []) {
            return $this->supported;
        }

        $found = [];

        foreach ($this->paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            foreach ((array) glob($path . '/*.php') as $file) {
                if (is_string($file)) {
                    $found[basename($file, '.php')] = true;
                }
            }
        }

        return $this->supported = array_keys($found);
    }

    // ------------------------------------------------------------ translating

    /**
     * Translate a message, falling back to the message itself.
     *
     * @param string               $message Source-language text, used as the key.
     * @param array<string, mixed> $params  Values for {placeholder} substitution.
     * @param string|null          $locale  Force a specific locale; null uses the active one.
     */
    public function t(string $message, array $params = [], ?string $locale = null): string
    {
        $target = $locale ?? $this->getLocale();
        $translated = $this->lookup($message, $target);

        if ($params === []) {
            return $translated;
        }

        return $this->interpolate($translated, $params);
    }

    /**
     * Resolve a key through the locale chain, ending at the message itself.
     */
    private function lookup(string $message, string $locale): string
    {
        foreach ($this->chain($locale) as $candidate) {
            $catalog = $this->catalog($candidate);

            if (isset($catalog[$message]) && $catalog[$message] !== '') {
                return $catalog[$message];
            }
        }

        return $message;
    }

    /**
     * The locales to try, most specific first, always ending at the default.
     *
     * `el-GR` tries `el-GR`, then `el`, then the configured default, and only
     * then gives up and returns the source. A regional variant therefore
     * inherits its base language instead of going straight to English, which
     * is the difference between "mostly translated" and "not translated".
     *
     * @return list<string>
     */
    private function chain(string $locale): array
    {
        $chain = [];

        foreach ($this->spellings($locale) as $spelling) {
            $chain[] = $spelling;
        }

        if ($locale !== $this->defaultLocale) {
            foreach ($this->spellings($this->defaultLocale) as $spelling) {
                $chain[] = $spelling;
            }
        }

        return array_values(array_unique($chain));
    }

/**
 * Locale names appear on disk as `el`, `el-GR`, `el_GR` or any case. All
 * four are plausible in a header, a query string, or a filename — and the
 * BASE language has to be among the candidates, or `el-GR` would never reach
 * the `el` catalog that exists to serve it.
 *
 * @return list<string>
 */
    private function spellings(string $locale): array
    {
        $locale = trim($locale);

        if ($locale === '') {
            return [];
        }

        $normalised = str_replace('_', '-', $locale);

        $candidates = [
            $normalised,
            strtolower($normalised),
            $locale,
            strtolower($locale),
            strtolower(str_replace('-', '_', $normalised)),
        ];

        $primary = strtolower((string) strstr($normalised, '-', true));

        if ($primary !== '') {
            $candidates[] = $primary;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Load and merge every catalog that exists for a locale.
     *
     * Later paths win, so an application's catalog overrides the framework's
     * for the same key — the framework can be corrected for one application
     * without being patched.
     *
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        if (isset($this->catalogs[$locale])) {
            return $this->catalogs[$locale];
        }

        $merged = [];

        foreach ($this->spellings($locale) as $spelling) {
            foreach ($this->paths as $path) {
                $file = $path . '/' . $spelling . '.php';

                if (!is_file($file)) {
                    continue;
                }

                /** @var mixed $loaded */
                $loaded = require $file;

                if (is_array($loaded)) {
                    foreach ($loaded as $key => $value) {
                        if (is_string($value) && $value !== '') {
                            $merged[(string) $key] = $value;
                        }
                    }
                }
            }
        }

        return $this->catalogs[$locale] = $merged;
    }

    /**
     * Substitute {name} placeholders.
     *
     * A placeholder with no matching parameter is left alone rather than
     * blanked: a half-filled sentence is a visible bug, an empty one is a
     * silent one.
     *
     * @param array<string, mixed> $params
     */
    private function interpolate(string $message, array $params): string
    {
        return self::substitute($message, $params);
    }

    /**
     * Placeholder substitution, usable with no translator in hand.
     *
     * Static and separate because the t() helper must interpolate even when no
     * request is in progress — a console command, a queue worker before its
     * scope exists, a test. Returning the raw message there would put a
     * literal {field} in front of a user, which reads as a broken template
     * rather than as an untranslated string.
     *
     * @param array<string, mixed> $params
     */
    public static function substitute(string $message, array $params): string
    {
        $replacements = [];

        foreach ($params as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return $replacements === [] ? $message : strtr($message, $replacements);
    }
}