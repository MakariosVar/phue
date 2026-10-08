<?php

declare(strict_types=1);

namespace Phue\Core\Security;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Immutable Set-Cookie descriptor.
 *
 * Exists because Phue\Core\Response::setHeader() replaces a header case-insensitively
 * and Response::$headers is a flat array<string, string>, so two Set-Cookie headers on
 * one response cannot be represented (nor emitted: PHP's header() replaces by name).
 * Cookies therefore live in their own list and are rendered one header line each.
 *
 * Every value that reaches a response header line is validated here. A cookie value is
 * attacker-influenceable input (it comes back from the client on the next request), so
 * accepting a raw CR or LF would be response splitting.
 *
 * @package Phue\Core\Security
 */
final class Cookie
{
    /**
     * RFC 6265 cookie-name grammar (token). Anything outside it cannot be emitted safely.
     *
     * @var string
     */
    private const NAME_PATTERN = '/^[\x21\x23-\x27\x2A\x2B\x2D\x2E\x30-\x39\x41-\x5A\x5E-\x7A\x7C\x7E]+$/';

    /**
     * RFC 6265 cookie-octet grammar: printable US-ASCII minus whitespace, DQUOTE,
     * comma, semicolon and backslash. Excludes CR, LF and every other control
     * character, which is what closes the response-splitting vector.
     *
     * @var string
     */
    private const VALUE_PATTERN = '/^[\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]*$/';

    /**
     * Canonical SameSite attribute values, keyed by their lowercase input form.
     *
     * @var array<string, string>
     */
    private const SAME_SITE = [
        'lax' => 'Lax',
        'strict' => 'Strict',
        'none' => 'None',
    ];

    /**
     * Canonical attribute emission order. Fixed so tests and snapshots stay stable.
     *
     * @var array<int, string>
     */
    private const ATTRIBUTE_ORDER = ['Max-Age', 'Domain', 'Path', 'Expires', 'Secure', 'HttpOnly', 'SameSite'];

    /**
     * @param string $name Cookie name (RFC 6265 token).
     * @param string $value Cookie value (RFC 6265 cookie-octet).
     * @param string $path Path attribute; defaults to '/' so the cookie covers the whole app.
     * @param string|null $domain Domain attribute; null keeps the cookie host-only.
     * @param int|null $maxAge Max-Age attribute in seconds; null omits it.
     * @param DateTimeInterface|null $expires Expires attribute; null omits it.
     * @param bool $secure Marks the cookie HTTPS-only.
     * @param bool $httpOnly Hides the cookie from document.cookie.
     * @param string|null $sameSite 'Lax', 'Strict', 'None' or null to omit the attribute.
     *
     * @throws InvalidArgumentException When any attribute cannot be emitted safely.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $value = '',
        public readonly string $path = '/',
        public readonly ?string $domain = null,
        public readonly ?int $maxAge = null,
        public readonly ?DateTimeInterface $expires = null,
        public readonly bool $secure = false,
        public readonly bool $httpOnly = false,
        public readonly ?string $sameSite = null,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Cookie name contains characters that are illegal in RFC 6265.');
        }

        if (preg_match(self::VALUE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Cookie value contains characters that are illegal in RFC 6265.');
        }

        if ($path !== '' && $path[0] !== '/') {
            throw new InvalidArgumentException('Cookie path must be empty or start with "/".');
        }

        if ($domain !== null && preg_match(self::NAME_PATTERN, $domain) !== 1) {
            throw new InvalidArgumentException('Cookie domain is not a valid host.');
        }

        if ($sameSite !== null) {
            $normalized = strtolower($sameSite);
            if (!isset(self::SAME_SITE[$normalized])) {
                throw new InvalidArgumentException('SameSite must be one of: Lax, Strict, None.');
            }

            // Browsers reject SameSite=None without Secure. Failing loudly here beats
            // shipping a cross-site cookie the client silently drops.
            if (self::SAME_SITE[$normalized] === 'None' && !$secure) {
                throw new InvalidArgumentException('SameSite=None requires the Secure attribute.');
            }
        }
    }

    /**
     * Canonical SameSite value, or null when the attribute is omitted.
     *
     * @return string|null
     */
    public function getSameSite(): ?string
    {
        return $this->sameSite === null ? null : self::SAME_SITE[strtolower($this->sameSite)];
    }

    /**
     * Build a deletion cookie: empty value, Max-Age=0, Expires in the past.
     *
     * @param string $name Cookie name to remove.
     * @param string $path Path the original cookie was set with; it must match to be removed.
     * @param string|null $domain Domain the original cookie was set with.
     * @return self
     */
    public static function removal(string $name, string $path = '/', ?string $domain = null): self
    {
        return new self(
            name: $name,
            value: '',
            path: $path,
            domain: $domain,
            maxAge: 0,
            expires: new DateTimeImmutable('@0', new DateTimeZone('UTC')),
            secure: true,
            httpOnly: true,
            sameSite: 'Lax',
        );
    }

    /**
     * Whether this cookie instructs the client to delete the stored value.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        if ($this->maxAge !== null) {
            return $this->maxAge <= 0;
        }

        return $this->expires !== null && $this->expires->getTimestamp() <= 0;
    }

    /**
     * Render the full Set-Cookie header value, attributes in a fixed order.
     *
     * @return string
     */
    public function toHeaderString(): string
    {
        $attributes = [];

        if ($this->maxAge !== null) {
            $attributes['Max-Age'] = (string) $this->maxAge;
        }

        if ($this->domain !== null) {
            $attributes['Domain'] = $this->domain;
        }

        if ($this->path !== '') {
            $attributes['Path'] = $this->path;
        }

        if ($this->expires !== null) {
            $attributes['Expires'] = gmdate('D, d M Y H:i:s', $this->expires->getTimestamp()) . ' GMT';
        }

        if ($this->secure) {
            $attributes['Secure'] = '';
        }

        if ($this->httpOnly) {
            $attributes['HttpOnly'] = '';
        }

        $sameSite = $this->getSameSite();
        if ($sameSite !== null) {
            $attributes['SameSite'] = $sameSite;
        }

        $pairs = [];
        foreach (self::ATTRIBUTE_ORDER as $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                $value = $attributes[$attribute];
                $pairs[] = $value === '' ? $attribute : $attribute . '=' . $value;
            }
        }

        return $this->name . '=' . $this->value . ($pairs === [] ? '' : '; ' . implode('; ', $pairs));
    }

    /**
     * Convenience factory for a session-style cookie that expires when the browser closes.
     *
     * @param string $name Cookie name.
     * @param string $value Cookie value.
     * @param bool $secure Mark the cookie HTTPS-only.
     * @param bool $httpOnly Hide the cookie from document.cookie.
     * @param string|null $sameSite SameSite attribute.
     * @param string|null $domain Domain attribute.
     * @param string $path Path attribute.
     * @return self
     */
    public static function create(
        string $name,
        string $value,
        bool $secure = false,
        bool $httpOnly = false,
        ?string $sameSite = 'Lax',
        ?string $domain = null,
        string $path = '/',
    ): self {
        return new self(
            name: $name,
            value: $value,
            path: $path,
            domain: $domain,
            maxAge: null,
            expires: null,
            secure: $secure,
            httpOnly: $httpOnly,
            sameSite: $sameSite,
        );
    }
}