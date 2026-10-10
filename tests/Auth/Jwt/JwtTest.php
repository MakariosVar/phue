<?php

declare(strict_types=1);

namespace Phue\Tests\Auth\Jwt;

use PHPUnit\Framework\TestCase;
use Phue\Modules\Auth\Jwt\Jwt;
use Phue\Modules\Auth\Jwt\JwtConfig;

/**
 * Test suite for Jwt utility class
 * 
 * @package Phue\Tests\Auth\Jwt
 */
class JwtTest extends TestCase
{
    private JwtConfig $config;
    private array $validPayload;

    protected function setUp(): void
    {
        $this->config = new JwtConfig('test-secret-key-for-testing', [
            'ttl' => 3600, // 1 hour
            'algorithm' => 'HS256',
        ]);

        $this->validPayload = [
            'sub' => '1234567890',
            'name' => 'Test User',
            'iat' => time(),
        ];
    }

    public function testCanEncodeAndDecodeJwt(): void
    {
        $token = Jwt::encode($this->validPayload, $this->config);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Token should have three parts separated by dots
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $decoded = Jwt::decode($token, $this->config);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('sub', $decoded);
        $this->assertEquals('1234567890', $decoded['sub']);
        $this->assertArrayHasKey('name', $decoded);
        $this->assertEquals('Test User', $decoded['name']);
        // iat and exp should be added automatically
        $this->assertArrayHasKey('iat', $decoded);
        $this->assertArrayHasKey('exp', $decoded);
    }

    public function testDecodeInvalidTokenThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Jwt::decode('invalid-token', $this->config);
    }

    public function testDecodeTokenWithWrongSecretThrowsException(): void
    {
        $token = Jwt::encode($this->validPayload, $this->config);
        $wrongConfig = new JwtConfig('wrong-secret', [
            'ttl' => 3600,
            'algorithm' => 'HS256',
        ]);

        $this->expectException(\RuntimeException::class);
        Jwt::decode($token, $wrongConfig);
    }

    public function testExpiredTokenThrowsException(): void
    {
        $expiredConfig = new JwtConfig('test-secret-key-for-testing', [
            'ttl' => -1, // Expired
            'algorithm' => 'HS256',
        ]);

        $token = Jwt::encode($this->validPayload, $expiredConfig);

        $this->expectException(\RuntimeException::class);
        Jwt::decode($token, $this->config);
    }

    public function testCustomIssuerAndAudienceValidation(): void
    {
        $configWithIssAud = new JwtConfig('test-secret-key-for-testing', [
            'ttl' => 3600,
            'algorithm' => 'HS256',
            'issuer' => 'test-issuer',
            'audience' => 'test-audience',
        ]);

        $payload = [
            'sub' => 'user123',
            'iss' => 'test-issuer',
            'aud' => 'test-audience',
        ];

        $token = Jwt::encode($payload, $configWithIssAud);
        $decoded = Jwt::decode($token, $configWithIssAud);

        $this->assertEquals('test-issuer', $decoded['iss']);
        $this->assertEquals('test-audience', $decoded['aud']);
    }

    public function testInvalidIssuerThrowsException(): void
    {
        $configWithIssAud = new JwtConfig('test-secret-key-for-testing', [
            'ttl' => 3600,
            'algorithm' => 'HS256',
            'issuer' => 'test-issuer',
            'audience' => 'test-audience',
        ]);

        $payload = [
            'sub' => 'user123',
            'iss' => 'wrong-issuer', // Wrong issuer
            'aud' => 'test-audience',
        ];

        $token = Jwt::encode($payload, $configWithIssAud);

        $this->expectException(\RuntimeException::class);
        Jwt::decode($token, $configWithIssAud);
    }

    public function testInvalidAudienceThrowsException(): void
    {
        $configWithIssAud = new JwtConfig('test-secret-key-for-testing', [
            'ttl' => 3600,
            'algorithm' => 'HS256',
            'issuer' => 'test-issuer',
            'audience' => 'test-audience',
        ]);

        $payload = [
            'sub' => 'user123',
            'iss' => 'test-issuer',
            'aud' => 'wrong-audience', // Wrong audience
        ];

        $token = Jwt::encode($payload, $configWithIssAud);

        $this->expectException(\RuntimeException::class);
        Jwt::decode($token, $configWithIssAud);
    }

    public function testDifferentAlgorithms(): void
    {
        $payload = ['sub' => 'user123'];

        // Test HS384
        $config384 = new JwtConfig('test-secret-key-for-testing-longer-for-384', [
            'ttl' => 3600,
            'algorithm' => 'HS384',
        ]);
        $token384 = Jwt::encode($payload, $config384);
        $decoded384 = Jwt::decode($token384, $config384);
        $this->assertEquals('user123', $decoded384['sub']);

        // Test HS512
        $config512 = new JwtConfig('test-secret-key-for-testing-longer-key-for-512-algorithm', [
            'ttl' => 3600,
            'algorithm' => 'HS512',
        ]);
        $token512 = Jwt::encode($payload, $config512);
        $decoded512 = Jwt::decode($token512, $config512);
        $this->assertEquals('user123', $decoded512['sub']);
    }
}