<?php

declare(strict_types=1);

namespace Phue\Tests\Websocket\Stubs;

use Phue\Modules\Auth\IdentityInterface;

/**
 * The smallest identity the session authenticator can work with.
 *
 * It implements `findIdentity` itself on purpose. An ActiveRecord model does not: the call is
 * forwarded to the query builder, which has no such method, so a stub that only extends the base
 * class looks like a logged-out user rather than like a broken fixture.
 */
final class SessionUser implements IdentityInterface
{
    public function __construct(
        private readonly int|string $id = 1,
        private readonly string $username = 'admin',
        private readonly ?string $authKey = 'admin-token'
    ) {
    }

    public static function findIdentity(int|string $id): ?self
    {
        // Ids 1 and 2 exist; anything else is a session naming a user who is gone, which is the
        // case that has to come back as null rather than as a half-built identity.
        return in_array((int) $id, [1, 2], true) ? new self($id) : null;
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getAuthKey(): ?string
    {
        return $this->authKey;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public static function findByUsername(string $username): ?self
    {
        return new self(123, $username);
    }
}
