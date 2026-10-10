<?php

declare(strict_types=1);

namespace Phue\Tests\Auth\Jwt;

use PHPUnit\Framework\TestCase;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\Jwt\JwtConfig;
use Phue\Modules\Auth\Authenticator\SessionAuthenticator;
use Phue\Modules\Auth\Persistence\LoginStoreInterface;
use Phue\Modules\Auth\Persistence\SessionLoginStore;
use Phue\Modules\Auth\IdentityInterface;
use Phue\Core\Request;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Simple test user class that implements IdentityInterface
 */
class TestUser implements IdentityInterface
{
    public function __construct(
        private readonly int|string $id,
        private readonly ?string $authKey = null
    ) {}

    public function getId(): int|string
    {
        return $this->id;
    }

    public static function findIdentity(int|string $id): ?IdentityInterface
    {
        return new self($id);
    }

    public function getAuthKey(): ?string
    {
        return $this->authKey;
    }

    public static function findByUsername(string $username): ?IdentityInterface
    {
        return new self(123, 'user123');
    }
}

/**
 * Integration test showing JWT authenticator working with AuthManager
 * 
 * @package Phue\Tests\Auth\Jwt
 */
class JwtAuthIntegrationTest extends TestCase
{
    private JwtConfig $jwtConfig;
    private string $validJwtToken;
    private IdentityInterface $mockUser;
    private MockObject|LoginStoreInterface $mockLoginStore;

    protected function setUp(): void
    {
        $this->jwtConfig = new JwtConfig('test-secret-key-for-testing', [
            'ttl' => 3600,
            'algorithm' => 'HS256',
        ]);

        // Create a valid JWT token
        $payload = ['sub' => 'user123'];
        $this->validJwtToken = \Phue\Modules\Auth\Jwt\Jwt::encode($payload, $this->jwtConfig);

        // Create mock user
        $this->mockUser = $this->createMock(IdentityInterface::class);
        $this->mockUser->method('getId')->willReturn(123);
        $this->mockUser->method('getAuthKey')->willReturn('user123');

        // Create mock login store
        $this->mockLoginStore = $this->createMock(LoginStoreInterface::class);
    }

    public function testAuthManagerAuthenticatesWithJwtWhenConfigured(): void
    {
        // This test simulates what would happen in the AuthModule when JWT is configured
        // We'll manually create an AuthManager with JWT authenticator to test the flow
        
        $sessionAuth = new SessionAuthenticator(
            \stdClass::class, // We won't actually use this for JWT test
            new SessionLoginStore()
        );

        $jwtAuthenticator = new \Phue\Modules\Auth\Jwt\JwtAuthenticator(
            function (string $identifier): ?IdentityInterface {
                // Simple resolver that returns our mock user for user123
                return $identifier === 'user123' ? $this->mockUser : null;
            },
            $this->jwtConfig
        );

        $authManager = new \Phue\Modules\Auth\AuthManager([$sessionAuth, $jwtAuthenticator]);

        // Create mock request with JWT token
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer ' . $this->validJwtToken);

        // Authenticate using JWT
        $identity = $authManager->authenticate($request);
        
        $this->assertNotNull($identity);
        $this->assertInstanceOf(IdentityInterface::class, $identity);
        $this->assertSame(123, $identity->getId());
        
        // Check that the user is now considered authenticated
        $this->assertTrue($authManager->check());
        $this->assertSame($identity, $authManager->getUser());
    }

    public function testAuthManagerPrefersSessionOverJwtWhenBothPresent(): void
    {
        // Test that when both session and JWT are valid, the first authenticator in chain wins
        // In AuthModule, session authenticator is first, then Bearer, then JWT
        
        // Use real SessionAuthenticator with mock login store that returns a user ID
        $this->mockLoginStore->method('getId')->willReturn(123);
        $sessionAuth = new SessionAuthenticator(
            TestUser::class,
            $this->mockLoginStore
        );

        $jwtAuthenticator = new \Phue\Modules\Auth\Jwt\JwtAuthenticator(
            function (string $identifier): ?IdentityInterface {
                // This should NOT be called since session auth succeeds first
                $this->fail('JWT authenticator should not be called when session auth succeeds');
                return null;
            },
            $this->jwtConfig
        );

        $authManager = new \Phue\Modules\Auth\AuthManager([$sessionAuth, $jwtAuthenticator]);

        // Create mock request with JWT token
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer ' . $this->validJwtToken);

        // The session auth will return the user from the login store
        $identity = $authManager->authenticate($request);
        
        $this->assertNotNull($identity);
        // The identity should be from session auth (BaseUser found by ID 123)
        // Since we're using BaseUser, it will try to find by ID
        $this->assertInstanceOf(IdentityInterface::class, $identity);
    }

    public function testAuthManagerFallsBackToJwtWhenSessionFails(): void
    {
        // Test that when session auth fails, it tries JWT authenticator
        
        // Use real SessionAuthenticator with mock login store that returns null (no session)
        $this->mockLoginStore->method('getId')->willReturn(null);
        $sessionAuth = new SessionAuthenticator(
            TestUser::class,
            $this->mockLoginStore
        );

        $jwtAuthenticator = new \Phue\Modules\Auth\Jwt\JwtAuthenticator(
            function (string $identifier): ?IdentityInterface {
                return $identifier === 'user123' ? $this->mockUser : null;
            },
            $this->jwtConfig
        );
        
        $authManager = new \Phue\Modules\Auth\AuthManager([$sessionAuth, $jwtAuthenticator]);
        
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer ' . $this->validJwtToken);
        
        $identity = $authManager->authenticate($request);
        
        $this->assertNotNull($identity);
        $this->assertInstanceOf(IdentityInterface::class, $identity);
        $this->assertSame(123, $identity->getId());
    }

    public function testAuthManagerReturnsNullWhenAllAuthenticatorsFail(): void
    {
        // Test that when both session and JWT fail, returns null
        
        // Session auth returns null (no session)
        $this->mockLoginStore->method('getId')->willReturn(null);
        $sessionAuth = new SessionAuthenticator(
            TestUser::class,
            $this->mockLoginStore
        );

        // JWT authenticator that always fails
        $jwtAuthenticator = new \Phue\Modules\Auth\Jwt\JwtAuthenticator(
            function (string $identifier): ?IdentityInterface {
                return null; // Always fail
            },
            $this->jwtConfig
        );
        
        $authManager = new \Phue\Modules\Auth\AuthManager([$sessionAuth, $jwtAuthenticator]);
        
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer invalid-token');
        
        $identity = $authManager->authenticate($request);
        
        $this->assertNull($identity);
        $this->assertFalse($authManager->check());
    }
}