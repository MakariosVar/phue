<?php

declare(strict_types=1);

namespace Phue\Tests\Auth\Jwt;

use PHPUnit\Framework\TestCase;
use Phue\Modules\Auth\Jwt\JwtAuthenticator;
use Phue\Modules\Auth\Jwt\JwtConfig;
use Phue\Modules\Auth\IdentityInterface;
use Phue\Core\Request;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test suite for JwtAuthenticator class
 * 
 * @package Phue\Tests\Auth\Jwt
 */
class JwtAuthenticatorTest extends TestCase
{
    private JwtConfig $config;
    private string $validToken;
    private string $invalidToken;

    protected function setUp(): void
    {
        $this->config = new JwtConfig('test-secret-key-for-testing', [
            'ttl' => 3600,
            'algorithm' => 'HS256',
        ]);

        // Create a valid token for testing
        $payload = ['sub' => 'user123'];
        $this->validToken = \Phue\Modules\Auth\Jwt\Jwt::encode($payload, $this->config);
        
        // Create an invalid token (wrong secret)
        $wrongConfig = new JwtConfig('wrong-secret', [
            'ttl' => 3600,
            'algorithm' => 'HS256',
        ]);
        $this->invalidToken = \Phue\Modules\Auth\Jwt\Jwt::encode($payload, $wrongConfig);
    }

    public function testAuthenticateReturnsNullWhenNoAuthHeader(): void
    {
        $authenticator = $this->createAuthenticator();
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn(null);

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testAuthenticateReturnsNullWhenEmptyAuthHeader(): void
    {
        $authenticator = $this->createAuthenticator();
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('');

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testAuthenticateReturnsNullWhenNonBearerHeader(): void
    {
        $authenticator = $this->createAuthenticator();
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Basic abc123');

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testAuthenticateReturnsNullWhenEmptyToken(): void
    {
        $authenticator = $this->createAuthenticator();
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer ');

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testAuthenticateReturnsNullWhenInvalidToken(): void
    {
        $authenticator = $this->createAuthenticator();
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer invalid-token');

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testAuthenticateReturnsNullWhenTokenHasWrongSecret(): void
    {
        $authenticator = $this->createAuthenticator();
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer ' . $this->invalidToken);

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testAuthenticateReturnsIdentityWhenValidToken(): void
    {
        // Use a concrete test user instead of a mock to ensure method_exists works
        $testUser = new class(123, 'user123') implements IdentityInterface {
            private int|string $id;
            private ?string $authKey;
            
            public function __construct(int|string $id, ?string $authKey) {
                $this->id = $id;
                $this->authKey = $authKey;
            }
            
            public function getId(): int|string {
                return $this->id;
            }
            
            public static function findIdentity(int|string $id): ?IdentityInterface {
                return new static($id, 'user123');
            }
            
            public function getAuthKey(): ?string {
                return $this->authKey;
            }
        };

        $authenticator = new JwtAuthenticator(
            function (string $identifier) use ($testUser): ?IdentityInterface {
                return $identifier === 'user123' ? $testUser : null;
            },
            $this->config
        );

        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer ' . $this->validToken);

        $result = $authenticator->authenticate($request);
        
        $this->assertInstanceOf(IdentityInterface::class, $result);
        $this->assertSame(123, $result->getId());
    }

    public function testAuthenticateReturnsNullWhenIdentityResolverReturnsNull(): void
    {
        $authenticator = new JwtAuthenticator(
            function (string $identifier): ?IdentityInterface {
                return null; // Always return null
            },
            $this->config
        );

        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer ' . $this->validToken);

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testAuthenticateReturnsNullWhenTokenIsExpired(): void
    {
        $expiredConfig = new JwtConfig('test-secret-key-for-testing', [
            'ttl' => -1, // Expired
            'algorithm' => 'HS256',
        ]);
        
        $payload = ['sub' => 'user123'];
        $expiredToken = \Phue\Modules\Auth\Jwt\Jwt::encode($payload, $expiredConfig);

        $authenticator = new JwtAuthenticator(
            function (string $identifier): ?IdentityInterface {
                $mock = $this->createMock(IdentityInterface::class);
                $mock->method('getId')->willReturn(123);
                return $mock;
            },
            $this->config
        );

        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer ' . $expiredToken);

        $this->assertNull($authenticator->authenticate($request));
    }

    /**
     * Create a JwtAuthenticator with a mock identity resolver
     * 
     * @return JwtAuthenticator
     */
    private function createAuthenticator(): JwtAuthenticator
    {
        return new JwtAuthenticator(
            function (string $identifier): ?IdentityInterface {
                if ($identifier === 'user123') {
                    $mock = $this->createMock(IdentityInterface::class);
                    $mock->method('getId')->willReturn(123);
                    $mock->method('getAuthKey')->willReturn('user123');
                    return $mock;
                }
                return null;
            },
            $this->config
        );
    }
}