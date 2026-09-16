<?php

declare(strict_types=1);

namespace Phue\Modules\Auth\Authenticator;

use Phue\Modules\Auth\IdentityInterface;
use Phue\Core\Request;

/**
 * Contract for request authenticators using Phue Core Request.
 *
 * @package Phue\Modules\Auth\Authenticator
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
