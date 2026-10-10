<?php

declare(strict_types=1);

namespace Phue\Modules\HttpSigning;

use Phue\Core\Config;
use RuntimeException;

/**
 * Manages signing keys for HTTP response signing.
 *
 * Supports both symmetric (HMAC-SHA256) and asymmetric (RSA-PSS-SHA256) algorithms.
 * Handles key rotation via key IDs.
 *
 * @package Phue\Modules\HttpSigning
 */
final class SigningKeyManager
{
    /**
     * Minimum secret length for HMAC (32 bytes = 256 bits).
     */
    private const MIN_HMAC_KEY_BYTES = 32;

    /**
     * Values that represent "no key" in env/config.
     *
     * @var array<int, string>
     */
    private const NULL_LITERALS = ['', 'empty', '(empty)', 'null', '(null)', 'false', '(false)'];

    /**
     * Create a new key manager.
     *
     * @param string        $algorithm       Signing algorithm identifier
     * @param array<string, array> $keys          Key configurations indexed by key ID
     * @param array{signature: string, algorithm: string, key_id: string} $headerNames Header names for signature transport
     * @param bool          $includePublicKey Whether to include public key in responses (RSA only)
     */
    private function __construct(
        private readonly string $algorithm,
        private readonly array $keys,
        private readonly array $headerNames,
        private readonly bool $includePublicKey
    ) {}

    /**
     * Build manager from application config.
     *
     * @param Config $config Application configuration
     * @return self
     * @throws RuntimeException When configuration is invalid
     */
    public static function fromConfig(Config $config): self
    {
        $signingConfig = $config->get('http-signing', []);

        $algorithm = $signingConfig['algorithm'] ?? 'hmac-sha256';
        if (!in_array($algorithm, ['hmac-sha256', 'rsa-pss-sha256'], true)) {
            throw new RuntimeException("Unsupported signing algorithm: {$algorithm}");
        }

        $keysConfig = $signingConfig['keys'] ?? [];
        if (empty($keysConfig)) {
            throw new RuntimeException('No signing keys configured (http-signing.keys)');
        }

        $keys = [];
        foreach ($keysConfig as $role => $keyConfig) {
            if (!isset($keyConfig['id'], $keyConfig['secret'])) {
                throw new RuntimeException("Key '{$role}' missing 'id' or 'secret'");
            }

            $keyId = $keyConfig['id'];
            $secret = $keyConfig['secret'];

            if ($algorithm === 'hmac-sha256') {
                $keys[$keyId] = self::parseHmacSecret($secret, $keyId);
            } else {
                $keys[$keyId] = self::parseRsaKeys($secret, $keyConfig['public_key'] ?? null, $keyId);
            }
        }

        $headerNames = $signingConfig['headers'] ?? [
            'signature' => 'X-Response-Signature',
            'algorithm' => 'X-Response-Signature-Algo',
            'key_id'    => 'X-Response-Signature-KeyId',
        ];

        $includePublicKey = (bool) ($signingConfig['include_public_key'] ?? false);

        return new self($algorithm, $keys, $headerNames, $includePublicKey);
    }

    /**
     * Parse HMAC secret from config value (supports base64: prefix).
     *
     * @param string $secret Config value (raw or base64: prefixed)
     * @param string $keyId Key identifier for error messages
     * @return array{id: string, type: string, secret: string, algorithm: string}
     * @throws RuntimeException When secret is invalid
     */
    private static function parseHmacSecret(string $secret, string $keyId): array
    {
        $raw = $secret;
        if (str_starts_with($secret, 'base64:')) {
            $raw = base64_decode(substr($secret, 7), true);
            if ($raw === false) {
                throw new RuntimeException("Key '{$keyId}': invalid base64 secret");
            }
        }

        if (strlen($raw) < self::MIN_HMAC_KEY_BYTES) {
            throw new RuntimeException("Key '{$keyId}': secret too short (min " . self::MIN_HMAC_KEY_BYTES . " bytes)");
        }

        return [
            'id'       => $keyId,
            'type'     => 'hmac',
            'secret'   => $raw,
            'algorithm' => 'sha256',
        ];
    }

    /**
     * Parse RSA private/public key pair from PEM strings.
     *
     * @param string      $privateKeyPem Private key in PEM format
     * @param string|null $publicKeyPem  Public key in PEM format (optional)
     * @param string      $keyId         Key identifier for error messages
     * @return array{id: string, type: string, private: resource, public: resource, algorithm: string}
     * @throws RuntimeException When keys are invalid or OpenSSL not available
     */
    private static function parseRsaKeys(string $privateKeyPem, ?string $publicKeyPem, string $keyId): array
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException("RSA signing requires OpenSSL extension");
        }

        $privKey = openssl_pkey_get_private($privateKeyPem);
        if ($privKey === false) {
            throw new RuntimeException("Key '{$keyId}': invalid private key PEM");
        }

        $details = openssl_pkey_get_details($privKey);
        if ($details === false || !isset($details['bits']) || $details['bits'] < 2048) {
            throw new RuntimeException("Key '{$keyId}': RSA key must be at least 2048 bits");
        }

        $pubKey = null;
        if ($publicKeyPem !== null) {
            $pubKey = openssl_pkey_get_public($publicKeyPem);
            if ($pubKey === false) {
                throw new RuntimeException("Key '{$keyId}': invalid public key PEM");
            }
        } else {
            $pubKey = openssl_pkey_get_public($privateKeyPem);
        }

        return [
            'id'         => $keyId,
            'type'       => 'rsa',
            'private'    => $privKey,
            'public'     => $pubKey,
            'algorithm'  => 'sha256',
        ];
    }

    /**
     * Get the current (primary) signing key.
     *
     * @return array{id: string, type: string, secret|private: string|resource, public?: resource, algorithm: string}
     */
    public function getCurrentKey(): array
    {
        return array_values($this->keys)[0];
    }

    /**
     * Get all available keys (for rotation/verification).
     *
     * @return array<string, array{id: string, type: string, secret|private: string|resource, public?: resource, algorithm: string}>
     */
    public function getAllKeys(): array
    {
        return $this->keys;
    }

    /**
     * Get a specific key by ID.
     *
     * @param string $keyId Key identifier
     * @return array{id: string, type: string, secret|private: string|resource, public?: resource, algorithm: string}|null
     */
    public function getKey(string $keyId): ?array
    {
        return $this->keys[$keyId] ?? null;
    }

    /**
     * Sign a payload with the current key.
     *
     * @param string $payload Data to sign
     * @return string Base64url-encoded signature
     * @throws RuntimeException On signing failure
     */
    public function sign(string $payload): string
    {
        $key = $this->getCurrentKey();

        return match ($key['type']) {
            'hmac' => $this->signHmac($payload, $key),
            'rsa'  => $this->signRsa($payload, $key),
            default => throw new RuntimeException('Unknown key type'),
        };
    }

    /**
     * Verify a signature against a key.
     *
     * @param string $payload   Original data
     * @param string $signature Base64url-encoded signature
     * @param string $keyId     Key ID to verify against
     * @return bool True if signature is valid
     */
    public function verify(string $payload, string $signature, string $keyId): bool
    {
        $key = $this->getKey($keyId);
        if ($key === null) {
            return false;
        }

        $decodedSig = $this->base64UrlDecode($signature);
        if ($decodedSig === false) {
            return false;
        }

        return match ($key['type']) {
            'hmac' => $this->verifyHmac($payload, $decodedSig, $key),
            'rsa'  => $this->verifyRsa($payload, $decodedSig, $key),
            default => false,
        };
    }

    /**
     * Get header names used for signature transport.
     *
     * @return array{signature: string, algorithm: string, key_id: string}
     */
    public function getHeaderNames(): array
    {
        return $this->headerNames;
    }

    /**
     * Get algorithm identifier for header.
     *
     * @return string 'HS256' or 'RS256'
     */
    public function getAlgorithmIdentifier(): string
    {
        return $this->algorithm === 'hmac-sha256' ? 'HS256' : 'RS256';
    }

    /**
     * Whether to include public key in response headers.
     */
    public function shouldIncludePublicKey(): bool
    {
        return $this->includePublicKey;
    }

    /**
     * Get public key PEM for current key (RSA only).
     *
     * @return string|null Public key PEM or null if not RSA
     */
    public function getCurrentPublicKeyPem(): ?string
    {
        $key = $this->getCurrentKey();
        if ($key['type'] !== 'rsa') {
            return null;
        }

        $details = openssl_pkey_get_details($key['public']);
        return $details['key'] ?? null;
    }

    /**
     * Sign payload with HMAC-SHA256.
     *
     * @param string $payload Data to sign
     * @param array  $key     Key configuration
     * @return string Base64url-encoded signature
     */
    private function signHmac(string $payload, array $key): string
    {
        $mac = hash_hmac($key['algorithm'], $payload, $key['secret'], true);
        return $this->base64UrlEncode($mac);
    }

    /**
     * Verify HMAC signature using constant-time comparison.
     *
     * @param string $payload    Original data
     * @param string $signature  Raw signature bytes
     * @param array  $key        Key configuration
     * @return bool
     */
    private function verifyHmac(string $payload, string $signature, array $key): bool
    {
        $expected = hash_hmac($key['algorithm'], $payload, $key['secret'], true);
        return hash_equals($expected, $signature);
    }

    /**
     * Sign payload with RSA-PSS-SHA256.
     *
     * @param string $payload Data to sign
     * @param array  $key     Key configuration
     * @return string Base64url-encoded signature
     * @throws RuntimeException On signing failure
     */
    private function signRsa(string $payload, array $key): string
    {
        $success = openssl_sign($payload, $signature, $key['private'], OPENSSL_ALGO_SHA256);
        if (!$success) {
            throw new RuntimeException('RSA signing failed');
        }
        return $this->base64UrlEncode($signature);
    }

    /**
     * Verify RSA signature.
     *
     * @param string $payload    Original data
     * @param string $signature  Raw signature bytes
     * @param array  $key        Key configuration
     * @return bool
     */
    private function verifyRsa(string $payload, string $signature, array $key): bool
    {
        $result = openssl_verify($payload, $signature, $key['public'], OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /**
     * Base64url encode (RFC 4648 §5).
     *
     * @param string $data Binary data
     * @return string Base64url encoded string without padding
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64url decode (RFC 4648 §5).
     *
     * @param string $data Base64url encoded string
     * @return string|false Decoded binary data or false on failure
     */
    private function base64UrlDecode(string $data): string|false
    {
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        $data = strtr($data, '-_', '+/');
        return base64_decode($data, true);
    }

    /**
     * Cleanup OpenSSL resources on destruction.
     */
    public function __destruct()
    {
        foreach ($this->keys as $key) {
            if ($key['type'] === 'rsa') {
                if (is_resource($key['private'])) {
                    openssl_free_key($key['private']);
                }
                if (is_resource($key['public'])) {
                    openssl_free_key($key['public']);
                }
            }
        }
    }
}