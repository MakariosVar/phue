<?php

declare(strict_types=1);

namespace Phue\Core\I18n;

use Phue\Core\Request;

/**
 * Decides which language a request is answered in.
 *
 * The order is deliberate, most specific and most trustworthy first:
 *
 *   1. ?locale=          the visitor asked, right now, in this URL
 *   2. user's preference  a signed-in choice they made on purpose
 *   3. Accept-Language   what the browser says it prefers
 *   4. the default       configured, always available
 *
 * The query parameter outranks the stored preference on purpose: a user who
 * clicks a Greek link means Greek for that link. The reverse would make
 * language links impossible — every click would be overruled by whatever the
 * account says.
 *
 * Why the result is narrowed against the catalogs
 * ----------------------------------------------
 * Without narrowing, a browser advertising `de-CH,de;q=0.9` on an application
 * that ships only English answers German requests in English anyway — but the
 * application has no way to KNOW that, because getLocale() would report "de"
 * and every date format, number format and translated label would follow a
 * locale it has no catalog for. Narrowing to a locale that actually exists
 * keeps what is reported and what is available in agreement.
 *
 * One language per request. A page that renders half in one language and half
 * in another is worse than a page consistently in the wrong one, because the
 * reader can no longer trust either half.
 */
final class LocaleResolver
{
    public function __construct(
        private readonly string $defaultLocale = 'en',
        /** @var list<string> When empty, every catalog on the search path is allowed. */
        private readonly array $supportedLocales = [],
    ) {}

    /**
     * Resolve the locale for a request.
     *
     * @param Request|null $request         Incoming request; null skips the sources that need one.
     * @param string|null  $userPreference  A stored preference from the authenticated user.
     * @param string|null  $defaultOverride Overrides the configured default for this call.
     */
    public function resolve(
        ?Request $request = null,
        ?string $userPreference = null,
        ?string $defaultOverride = null,
    ): string {
        $default = $defaultOverride ?? $this->defaultLocale;
        $candidates = [];

        $requested = $this->queryLocale($request);

        if ($requested !== null) {
            $candidates[] = $requested;
        }

        if ($userPreference !== null && trim($userPreference) !== '') {
            $candidates[] = $userPreference;
        }

        if ($request !== null) {
            foreach ($this->preferredLanguages($request) as $language) {
                $candidates[] = $language;
            }
        }

        $candidates[] = $default;

        foreach ($candidates as $candidate) {
            $match = $this->narrow($candidate, $default);

            if ($match !== null) {
                return $match;
            }
        }

        return $default;
    }

    /**
     * The ?locale= parameter, if it is a plain locale name.
     *
     * Anything else — an empty value, a path, a SQL fragment — is ignored
     * rather than sanitised and used. The value goes no further than a
     * catalog filename lookup, and the safest way to make that safe is not to
     * accept the shape that would need sanitising.
     */
    private function queryLocale(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $query = $request->getQueryParams();
        $value = $query['locale'] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$/', $value) === 1 ? $value : null;
    }

    /**
     * Parse Accept-Language, most preferred first.
     *
     * The q values are sorted rather than taken in header order: headers are
     * not required to be ordered by preference, and browsers are not always
     * careful about it.
     *
     * @return list<string>
     */
    private function preferredLanguages(Request $request): array
    {
        $header = $request->getHeader('Accept-Language') ?? '';

        if ($header === '') {
            return [];
        }

        $weighted = [];

        foreach (explode(',', $header) as $index => $part) {
            $segments = explode(';', $part);
            $language = trim($segments[0]);

            if ($language === '' || $language === '*') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $segment) {
                $segment = trim($segment);

                if (str_starts_with($segment, 'q=')) {
                    $parsed = (float) substr($segment, 2);
                    $quality = is_nan($parsed) ? 0.0 : $parsed;
                }
            }

            if ($quality <= 0.0) {
                continue;
            }

            // Index breaks ties, keeping the header's own order among equals.
            $weighted[] = ['language' => $language, 'quality' => $quality, 'index' => $index];
        }

        usort(
            $weighted,
            static fn (array $a, array $b): int => ($b['quality'] <=> $a['quality']) ?: ($a['index'] <=> $b['index'])
        );

        return array_map(static fn (array $entry): string => $entry['language'], $weighted);
    }

    /**
     * Find the closest catalog for a requested language.
     *
     * Tries the tag as written, then without its region, so `fr-CA` is served
     * by an `fr` catalog when that is all there is. Never returns null for a
     * value equal to the default: the default is always answerable, catalog
     * or not, because the source string itself is the final fallback.
     */
    private function narrow(string $candidate, string $default): ?string
    {
        $normalised = str_replace('_', '-', trim($candidate));
        $variants = [$normalised, strtolower($normalised)];

        $primary = strtolower((string) strstr($normalised, '-', true));

        if ($primary !== '') {
            $variants[] = $primary;
        }

        $variants = array_values(array_unique($variants));

        if ($this->supportedLocales !== []) {
            foreach ($variants as $variant) {
                if (in_array($variant, $this->supportedLocales, true)) {
                    return $variant;
                }
            }
        }

        $defaultNormalised = strtolower(str_replace('_', '-', $default));

        foreach ($variants as $variant) {
            if ($variant === $defaultNormalised) {
                return $variant;
            }
        }

        return null;
    }
}