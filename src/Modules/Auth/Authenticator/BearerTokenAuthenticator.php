<?php

declare(strict_types=1);

namespace Phue\Modules\Auth\Authenticator;

use Phue\Modules\Auth\IdentityInterface;
use Phue\Core\Request;

/**
 * Bearer Token authenticator using native Phue Request context.
 *
 * @package Phue\Modules\Auth\Authenticator
 */
final class BearerTokenAuthenticator implements AuthenticatorInterface
{
    /**
     * @param callable(string): ?IdentityInterface $tokenResolver
     */
    public function __construct(
        private $tokenResolver
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

        return ($this->tokenResolver)($token);
    }
}
