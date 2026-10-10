<?php

declare(strict_types=1);

namespace Phue\Modules\Auth\Jwt;

use InvalidArgumentException;
use RuntimeException;

/**
 * JWT utility for encoding and decoding JSON Web Tokens
 * 
 * Supports HMAC-based algorithms (HS256, HS384, HS512)
 * 
 * @package Phue\Modules\Auth\Jwt
 */
class Jwt
{
    /**
     * Encode a payload into a JWT token
     * 
     * @param array<string, mixed> $payload Claims to include in the JWT
     * @param JwtConfig $config JWT configuration (secret, algorithm, etc.)
     * @return string JWT token
     * @throws InvalidArgumentException
     */
    public static function encode(array $payload, JwtConfig $config): string
    {
        $header = [
            'alg' => $config->algorithm,
            'typ' => 'JWT',
        ];
        
        $payload = array_merge($payload, [
            'iat' => time(), // Issued at
            'exp' => time() + $config->ttl, // Expires at
        ]);
        
        if ($config->issuer !== null && !isset($payload['iss'])) {
            $payload['iss'] = $config->issuer;
        }
        
        if ($config->audience !== null && !isset($payload['aud'])) {
            $payload['aud'] = $config->audience;
        }
        
        $headerJson = self::jsonEncode($header);
        $payloadJson = self::jsonEncode($payload);
        
        $base64Header = self::base64UrlEncode($headerJson);
        $base64Payload = self::base64UrlEncode($payloadJson);
        
        $signature = self::sign(
            $base64Header . '.' . $base64Payload,
            $config->secret,
            $config->algorithm
        );
        
        return $base64Header . '.' . $base64Payload . '.' . $signature;
    }
    
    /**
     * Decode and verify a JWT token
     * 
     * @param string $token JWT token to verify and decode
     * @param JwtConfig $config JWT configuration (secret, algorithm, etc.)
     * @return array<string, mixed> Decoded payload
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public static function decode(string $token, JwtConfig $config): array
    {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Invalid JWT token format');
        }
        
        [$base64Header, $base64Payload, $signature] = $parts;
        
        // Verify signature
        $expectedSignature = self::sign(
            $base64Header . '.' . $base64Payload,
            $config->secret,
            $config->algorithm
        );
        
        if (!self::hashEquals($signature, $expectedSignature)) {
            throw new RuntimeException('Invalid JWT signature');
        }
        
        // Decode payload
        $payloadJson = self::base64UrlDecode($base64Payload);
        $payload = self::jsonDecode($payloadJson, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode JWT payload');
        }
        
        // Validate standard claims
        self::validateStandardClaims($payload, $config);
        
        return $payload;
    }
    
    /**
     * Validate standard JWT claims
     * 
     * @param array<string, mixed> $payload Decoded JWT payload
     * @param JwtConfig $config JWT configuration
     * @throws RuntimeException
     */
    private static function validateStandardClaims(array $payload, JwtConfig $config): void
    {
        // Validate expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new RuntimeException('JWT token has expired');
        }
        
        // Validate issuer
        if ($config->issuer !== null && isset($payload['iss']) && $payload['iss'] !== $config->issuer) {
            throw new RuntimeException('Invalid JWT issuer');
        }
        
        // Validate audience
        if ($config->audience !== null && isset($payload['aud']) && $payload['aud'] !== $config->audience) {
            throw new RuntimeException('Invalid JWT audience');
        }
    }
    
    /**
     * Sign data using HMAC algorithm
     * 
     * @param string $data Data to sign
     * @param string $secret Secret key
     * @param string $algorithm Algorithm (HS256, HS384, HS512)
     * @return string Base64url encoded signature
     * @throws InvalidArgumentException
     */
    private static function sign(string $data, string $secret, string $algorithm): string
    {
        switch ($algorithm) {
            case 'HS256':
                $hash = 'sha256';
                break;
            case 'HS384':
                $hash = 'sha384';
                break;
            case 'HS512':
                $hash = 'sha512';
                break;
            default:
                throw new InvalidArgumentException("Unsupported algorithm: {$algorithm}");
        }
        
        $signature = hash_hmac($hash, $data, $secret, true);
        return self::base64UrlEncode($signature);
    }
    
    /**
     * Base64url encode string
     * 
     * @param string $input Input string
     * @return string Base64url encoded string
     */
    private static function base64UrlEncode(string $input): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($input));
    }
    
    /**
     * Base64url decode string
     * 
     * @param string $input Base64url encoded string
     * @return string Decoded string
     * @throws InvalidArgumentException
     */
    private static function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $input), true);
    }
    
    /**
     * JSON encode with error handling
     * 
     * @param mixed $value Value to encode
     * @return string JSON string
     * @throws RuntimeException
     */
    private static function jsonEncode(mixed $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        return $json;
    }
    
    /**
     * JSON decode with error handling
     * 
     * @param string $json JSON string
     * @param bool $associative Whether to return associative array
     * @return mixed Decoded value
     */
    private static function jsonDecode(string $json, bool $associative = false)
    {
        return json_decode($json, $associative, 512, JSON_THROW_ON_ERROR);
    }
    
    /**
     * Constant time string comparison to prevent timing attacks
     * 
     * @param string $a First string
     * @param string $b Second string
     * @return bool True if strings are equal
     */
    private static function hashEquals(string $a, string $b): bool
    {
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }
        
        // Fallback for older PHP versions
        $lenA = strlen($a);
        $lenB = strlen($b);
        
        if ($lenA !== $lenB) {
            return false;
        }
        
        $result = 0;
        for ($i = 0; $i < $lenA; $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }
        
        return $result === 0;
    }
}