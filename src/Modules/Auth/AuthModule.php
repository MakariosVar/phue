<?php

declare(strict_types=1);

namespace Phue\Modules\Auth;

use Phue\Core\Module;
use Phue\Core\Container;
use Phue\Core\Config;
use Phue\Core\Request;
use Phue\Core\Security\CurrentUserInterface;
use Phue\Modules\Auth\Authenticator\BearerTokenAuthenticator;
use Phue\Modules\Auth\Authenticator\SessionAuthenticator;
use Phue\Modules\Auth\Persistence\LoginStoreInterface;
use Phue\Modules\Auth\Persistence\SessionLoginStore;

/**
 * Class AuthModule
 *
 * Serves as the bootstrapping module for the authentication system.
 * Configures framework dependency injections, handles authentication resolvers, 
 * session storage persistence, user accessors, and RBAC management.
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
     *
     * @return void
     */
    public function init(): void
    {
        $this->bindings();
    }

    /**
     * Register dependency injections
     *
     * @return void
     */
    protected function bindings(): void
    {
        // Bind LoginStoreInterface to SessionLoginStore via a factory closure
        $this->container->singleton(
            LoginStoreInterface::class,
            fn(): LoginStoreInterface => new SessionLoginStore()
        );

        // AuthManager scoped binding
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

                // SessionAuthenticator now relies on LoginStoreInterface instead of a hardcoded session key
                $sessionAuth = new SessionAuthenticator(
                    identityClass: \App\Models\User::class,
                    loginStore: $c->get(LoginStoreInterface::class)
                );

                return new AuthManager([$sessionAuth, $bearerAuth]);
            }
        );

        // RbacManager Singleton
        $this->container->singleton(
            RbacManager::class,
            fn(): RbacManager => new RbacManager('config/rbac_seeding.php')
        );

        // CurrentUserInterface scoped binding to prevent Swoole request leakage
        $this->container->scoped(
            CurrentUserInterface::class,
            fn(Container $c): CurrentUserInterface => new UserAccessor(
                authManager: $c->get(AuthManager::class),
                rbacManager: $c->get(RbacManager::class),
                loginStore: $c->get(LoginStoreInterface::class),
                request: $c->get(Request::class)
            )
        );
    }
}