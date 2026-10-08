<?php

declare(strict_types=1);

namespace Phue\Core\Security\Csrf;

/**
 * Typed view over the `app.security.csrf` configuration block.
 *
 * Defaults are the production posture. Anything loosened here is visible as a decision in
 * one place rather than scattered as a conditional across the middleware.
 *
 * @package Phue\Core\Security\Csrf
 */
final class CsrfConfig
{
    /**
     * Header carrying the token. Read case-insensitively, as all headers are.
     *
     * @var string
     */
    public const DEFAULT_HEADER_NAME = 'X-CSRF-Token';

    /**
     * Form field carrying the token for non-JS form submissions.
     *
     * @var string
     */
    public const DEFAULT_FIELD_NAME = '_csrf';

    /**
     * Cookie holding the token.
     *
     * @var string
     */
    public const DEFAULT_COOKIE_NAME = 'phue_csrf';

    /**
     * @param bool $enabled Master switch. Off means no token is issued and none is demanded.
     * @param mixed $appKey Raw APP_KEY value; anything unusable is reported by the token manager.
     * @param string $headerName Request header the token is read from.
     * @param string $fieldName Body field the token is read from.
     * @param string $cookieName Cookie the token is issued in.
     * @param string $cookiePath Path attribute for that cookie.
     * @param bool $requireSecureCookie Mark the cookie Secure regardless of detected transport.
     * @param string $cookieSameSite SameSite attribute for that cookie.
     * @param bool $issueTokenOnHtmlResponses Whether to attach a token cookie to HTML responses.
     * @param array<int, string> $safeMethods Methods that never change state and never need a token.
     * @param array<int, string> $nonAmbientAuthSchemes Authorization schemes that are not
     *                                                    ambient credentials, and therefore cannot be ridden by CSRF.
     *
     * @package Phue\Core\Security\Csrf
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly mixed $appKey = null,
        public readonly string $headerName = self::DEFAULT_HEADER_NAME,
        public readonly string $fieldName = self::DEFAULT_FIELD_NAME,
        public readonly string $cookieName = self::DEFAULT_COOKIE_NAME,
        public readonly string $cookiePath = '/',
        public readonly bool $requireSecureCookie = true,
        public readonly string $cookieSameSite = 'Strict',
        public readonly bool $issueTokenOnHtmlResponses = true,
        public readonly array $safeMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE'],
        public readonly array $nonAmbientAuthSchemes = ['bearer'],
    ) {
    }

    /**
     * Build from the raw config array, ignoring keys this version does not know about.
     *
     * @param mixed $config Whatever config storage returned for app.security.csrf
     * @return self
     */
    public static function fromArray(mixed $config): self
    {
        $defaults = new self();

        if (!is_array($config)) {
            return $defaults;
        }

        $string = static function (string $key, string $fallback) use ($config): string {
            $value = $config[$key] ?? null;

            return is_string($value) && $value !== '' ? $value : $fallback;
        };

        $boolean = static function (string $key, bool $fallback) use ($config): bool {
            return array_key_exists($key, $config) && is_bool($config[$key])
                ? $config[$key]
                : $fallback;
        };

        $stringList = static function (string $key, array $fallback) use ($config): array {
            $value = $config[$key] ?? null;

            if (!is_array($value)) {
                return $fallback;
            }

            $entries = [];

            foreach ($value as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $entries[] = strtoupper($entry);
                }
            }

            return $entries === [] ? $fallback : $entries;
        };

        return new self(
            enabled: $boolean('enabled', $defaults->enabled),
            appKey: $config['appKey'] ?? $defaults->appKey,
            headerName: $string('headerName', $defaults->headerName),
            fieldName: $string('fieldName', $defaults->fieldName),
            cookieName: $string('cookieName', $defaults->cookieName),
            cookiePath: $string('cookiePath', $defaults->cookiePath),
            requireSecureCookie: $boolean('requireSecureCookie', $defaults->requireSecureCookie),
            cookieSameSite: $string('cookieSameSite', $defaults->cookieSameSite),
            issueTokenOnHtmlResponses: $boolean('issueTokenOnHtmlResponses', $defaults->issueTokenOnHtmlResponses),
            safeMethods: $stringList('safeMethods', $defaults->safeMethods),
            nonAmbientAuthSchemes: $stringList('nonAmbientAuthSchemes', $defaults->nonAmbientAuthSchemes),
        );
    }
}