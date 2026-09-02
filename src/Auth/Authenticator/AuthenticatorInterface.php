<?php

declare(strict_types=1);

namespace Phue\Auth\Authenticator;

use Phue\Auth\IdentityInterface;
use Phue\Core\Request;

/**
 * Contract for request authenticators using Phue Core Request.
 *
 * @package Phue\Auth\Authenticator
 */
interface AuthenticatorInterface
{
    /**
     * Authenticates identity from HTTP Request.
     *
     * @param Request $request
     * @return IdentityInterface|null
     */
    public function authenticate(Request $request): ?IdentityInterface;
}