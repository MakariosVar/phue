<?php

declare(strict_types=1);

namespace Phue\Modules\Auth;

use Phue\Modules\Auth\Authenticator\AuthenticatorInterface;
use Phue\Core\Request;

/**
 * State manager for authenticating requests and retaining active identities.
 *
 * @package Phue\Modules\Auth
 */
final class AuthManager
{
    private ?IdentityInterface $identity = null;

    /**
     * @param AuthenticatorInterface[] $authenticators
     */
    public function __construct(
        private readonly array $authenticators
    ) {}

    /**
     * Authenticates the request using configured authenticators in chain.
     *
     * @param Request $request
     * @return IdentityInterface|null
     */
    public function authenticate(Request $request): ?IdentityInterface
    {
        foreach ($this->authenticators as $authenticator) {
            $identity = $authenticator->authenticate($request);
            if ($identity !== null) {
                $this->identity = $identity;
                return $identity;
            }
        }

        return null;
    }

    public function login(IdentityInterface $identity): void
    {
        $this->identity = $identity;
    }

    public function logout(): void
    {
        $this->identity = null;
    }

    public function getUser(): ?IdentityInterface
    {
        return $this->identity;
    }

    public function check(): bool
    {
        return $this->identity !== null;
    }
}
