<?php

declare(strict_types=1);

namespace Phue\Modules\Auth\Jwt;

use Phue\Modules\Auth\Authenticator\AuthenticatorInterface;
use Phue\Modules\Auth\IdentityInterface;
use Phue\Core\Request;
use InvalidArgumentException;

/**
 * JWT authenticator that validates tokens and resolves user identity
 * 
 * @package Phue\Modules\Auth\Jwt
 */
final class JwtAuthenticator implements AuthenticatorInterface
{
    /**
     * @param callable(string): ?IdentityInterface $identityResolver Resolves identity from JWT subject claim
     * @param JwtConfig $config JWT configuration
     */
    public function __construct(
        private $identityResolver,
        private JwtConfig $config
    ) {}

    public function authenticate(Request $request): ?IdentityInterface
    {
        $authHeader = $request->getHeader('Authorization') ?? '';
        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authHeader, 7));
        if (empty($token)) {
            return null;
        }

        try {
            $payload = Jwt::decode($token, $this->config);
            
            // Extract subject claim (sub) which should contain the user identifier
            if (!isset($payload['sub']) || !is_string($payload['sub'])) {
                return null;
            }
            
            // Resolve identity using the resolver callback
            $identity = ($this->identityResolver)($payload['sub']);
            
            // Additional validation: ensure the resolved identity matches the token
            if ($identity !== null && $this->validateIdentityMatchesToken($identity, $payload)) {
                return $identity;
            }
            
            return null;
        } catch (InvalidArgumentException| \RuntimeException) {
            // Token is invalid or expired
            return null;
        }
    }
    
    /**
     * Validate that the resolved identity matches the JWT token claims
     * 
     * @param IdentityInterface $identity Resolved identity
     * @param array<string, mixed> $payload JWT payload
     * @return bool True if identity matches token
     */
    private function validateIdentityMatchesToken(IdentityInterface $identity, array $payload): bool
    {
        // If the identity has a getAuthKey method, we can compare it with the token's sub claim
        // This is a common pattern where the 'sub' claim contains the user ID or auth key
        if (method_exists($identity, 'getAuthKey')) {
            $authKey = $identity->getAuthKey();
            if ($authKey !== null && is_string($authKey)) {
                return $authKey === $payload['sub'];
            }
        }
        
        // Fallback: compare identity ID with sub claim
        if (method_exists($identity, 'getId')) {
            $id = $identity->getId();
            // Convert both to string for comparison
            return (string)$id === (string)$payload['sub'];
        }
        
        // If we can't validate, assume it's OK (the resolver should have done proper validation)
        return true;
    }
}