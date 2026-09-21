<?php

declare(strict_types=1);

namespace Phue\Modules\Auth;

use Phue\Core\Module;
use Phue\Core\Container;
use Phue\Core\Config;
use Phue\Modules\Auth\Authenticator\BearerTokenAuthenticator;
use Phue\Modules\Auth\Authenticator\SessionAuthenticator;

/**
 * Class AuthModule
 *
 * Serves as the bootstrapping module for the authentication system.
 * Configures framework dependency injections, handles authentication resolvers, and registers REST API and console routes.
 *
 * @package Phue\Modules\Auth
 */
class AuthModule extends Module
{
    /**
     * Module ID
     *
     * @var string
     */
    public string $id = 'auth';

    /**
     * Module requirements
     *
     * @var array
     */
    public array $requires = [];

    /**
     * Check if module requires database
     *
     * @return bool
     */
    public function requiresDb(): bool
    {
        return false;
    }

    /**
     * Initialize module
     */
    public function init(): void
    {
        $this->bindings();
    }

    /**
     * Register dependency injections
     */
    protected function bindings(): void
    {
        // AuthManager scoped
        $this->container->scoped(
            AuthManager::class,
            function (Container $c): AuthManager {
                /** @var array<string, mixed> $authConfig */
                $authConfig = $c->has(Config::class)
                    ? $c->get(Config::class)->get('auth', [])
                    : [];

                /** @var callable|null $resolver */
                $resolver = $authConfig['authResolver']
                    ?? [\Phue\Modules\Auth\BaseUser::class, 'findIdentityByAuthKey'];

                $bearerAuth = new BearerTokenAuthenticator(
                    tokenResolver: static function (string $token) use ($resolver): mixed {
                        if (is_callable($resolver)) {
                            return $resolver($token);
                        }
                        return null;
                    }
                );

                $sessionAuth = new SessionAuthenticator(
                    identityClass: \App\Models\User::class,
                    sessionKey: '__user_id'
                );
                return new AuthManager([$sessionAuth, $bearerAuth]);

            }
        );

        // RbacManager Singleton
        $this->container->singleton(
            RbacManager::class,
            fn(): RbacManager => new RbacManager('config/rbac_seeding.php')
        );
    }
}

