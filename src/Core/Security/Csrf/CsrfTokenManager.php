<?php

declare(strict_types=1);

namespace Phue\Core\Security\Csrf;

use Phue\Core\Security\Csrf\Exceptions\MissingApplicationKeyException;

/**
 * Issues and verifies synchronizer CSRF tokens.
 *
 * A token is `base64url(nonce).base64url(hmac_sha256(nonce, key))`. The nonce carries the
 * randomness; the MAC is what makes the token unforgeable by whoever can write the
 * cookie. A plain random double-submit token does not survive an attacker who can set a
 * cookie on the victim's origin (a subdomain, a sibling app on the same registrable
 * domain), because that attacker can also echo a matching header.
 *
 * Nothing is stored server-side. That is the whole point: the hot path is one HMAC over
 * ~43 bytes and one hash_equals, with no session write, no lock, no database. It is
 * therefore identical under FPM, RoadRunner and Swoole, and needs no sticky
 * sessions when an application runs on more than one worker.
 *
 * @package Phue\Core\Security\Csrf
 */
final class CsrfTokenManager
{
    /**
     * Random bytes behind the nonce. 32 bytes is the 256-bit floor.
     *
     * @var int
     */
    private const NONCE_BYTES = 32;

    /**
     * Minimum size of the decoded application key. Anything shorter is rejected rather
     * than accepted, because a weak key silently turns every signature check into theatre.
     *
     * @var int
     */
    private const MIN_KEY_BYTES = 32;

    /**
     * Values Env::load() produces for a literal .env entry, or for an empty one. They can
     * reach this code as real strings depending on where the config was read from, and
     * each one means "no key" rather than "a key made of these letters".
     *
     * @var array<int, string>
     */
    private const NULL_LITERALS = ['', 'empty', '(empty)', 'null', '(null)', 'false', '(false)'];

    /**
     * @param string $key Raw application secret, used directly as the HMAC key
     */
    private function __construct(private readonly string $key)
    {
    }

    /**
     * Build a manager from the configured APP_KEY value.
     *
     * @param mixed $configured Whatever config storage returned: a string, null, or an
     *                          Env coercion of a missing .env entry.
     *
     * @return self
     * @throws MissingApplicationKeyException When the key is absent or unusable.
     */
    public static function fromConfigValue(mixed $configured): self
    {
        // A bool or null here means Env turned a missing/empty .env entry into it.
        $value = is_string($configured) ? trim($configured) : '';

        if (in_array(strtolower($value), self::NULL_LITERALS, true)) {
            throw MissingApplicationKeyException::absent();
        }

        if (!str_starts_with($value, 'base64:')) {
            throw MissingApplicationKeyException::unusable('expected a value prefixed with "base64:"');
        }

        $decoded = base64_decode(substr($value, 7), true);

        if ($decoded === false) {
            throw MissingApplicationKeyException::unusable('the part after "base64:" is not valid base64');
        }

        if (strlen($decoded) < self::MIN_KEY_BYTES) {
            throw MissingApplicationKeyException::unusable(
                'it decodes to ' . strlen($decoded) . ' bytes, at least ' . self::MIN_KEY_BYTES . ' are required'
            );
        }

        return new self($value);
    }

    /**
     * Issue a fresh token.
     *
     * @return string
     */
    public function generate(): string
    {
        $nonce = self::base64UrlEncode(random_bytes(self::NONCE_BYTES));

        return $nonce . '.' . self::base64UrlEncode($this->mac($nonce));
    }

    /**
     * Check a token presented by the client.
     *
     * Total by construction: every rejection is the same `false`, and nothing throws. The
     * input is fully attacker-controlled and sits on the hot path, so a thrown exception
     * would both turn a bad header into a 500 and give an attacker a way to tell
     * "malformed" from "wrong signature".
     *
     * Shape is checked by length before any character class and before any crypto, so a
     * hostile value costs a couple of string comparisons.
     *
     * @param string $presented Token as received, from the header or the form field
     * @return bool
     */
    public function verify(string $presented): bool
    {
        $separator = strpos($presented, '.');

        if ($separator === false) {
            return false;
        }

        $nonce = substr($presented, 0, $separator);
        $mac = substr($presented, $separator + 1);

        // A second dot means three segments, which no issued token ever has.
        if (str_contains($mac, '.')) {
            return false;
        }

        $expectedLength = self::base64UrlLength(self::NONCE_BYTES);

        if (strlen($nonce) !== $expectedLength || strlen($mac) !== $expectedLength) {
            return false;
        }

        if (!self::isBase64Url($nonce) || !self::isBase64Url($mac)) {
            return false;
        }

        // Constant-time: a short-circuiting comparison leaks how much of the signature
        // was correct, one byte at a time. Both sides are base64url so the comparison is
        // over equal-length strings.
        return hash_equals(self::base64UrlEncode($this->mac($nonce)), $mac);
    }

    /**
     * @param string $nonce base64url nonce
     * @return string raw HMAC-SHA256 digest
     */
    private function mac(string $nonce): string
    {
        return hash_hmac('sha256', $nonce, $this->key, true);
    }

    /**
     * @param string $value
     * @return bool
     */
    private static function isBase64Url(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]+\z/', $value) === 1;
    }

    /**
     * @param string $bytes
     * @return string
     */
    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Length of a base64url encoding of N bytes, without padding.
     *
     * @param int $bytes
     * @return int
     */
    private static function base64UrlLength(int $bytes): int
    {
        return intdiv($bytes * 8 + 5, 6);
    }
}