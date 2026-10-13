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
use Phue\Modules\Auth\BaseUser;
use Phue\Modules\Auth\Persistence\LoginStoreInterface;
use Phue\Modules\Auth\Persistence\SessionLoginStore;
use Phue\Modules\Auth\Jwt\JwtAuthenticator;
use Phue\Modules\Auth\Jwt\JwtConfig;

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
                //
                // The identity class is read from config for the same reason
                // authResolver is: it belongs to the application, not to the
                // framework. Naming \App\Models\User here made this module
                // unusable outside the framework's own demo app — a library that
                // references the consuming application's namespace is not a
                // library, and every install that did not have that exact class
                // fataled here instead of at the point of use.
                //
                // BaseUser is the fallback because it is already this module's
                // default identity (see authResolver above). An application that
                // wants a real table points identityClass at its own model.
                $sessionAuth = new SessionAuthenticator(
                    identityClass: $authConfig['identityClass'] ?? BaseUser::class,
                    loginStore: $c->get(LoginStoreInterface::class)
                );

                $authenticators = [$sessionAuth, $bearerAuth];

                // Add JWT authenticator if configured
                if (!empty($authConfig['jwt'])) {
                    $jwtConfig = $authConfig['jwt'];
                    $jwtSecret = $jwtConfig['secret'] ?? '';
                    $jwtConfigObj = new JwtConfig($jwtSecret, $jwtConfig);

                    $jwtAuthenticator = new JwtAuthenticator(
                        identityResolver: static function (string $identifier) use ($resolver): mixed {
                            if (is_callable($resolver)) {
                                return $resolver($identifier);
                            }
                            return null;
                        },
                        config: $jwtConfigObj
                    );

                    $authenticators[] = $jwtAuthenticator;
                }

                return new AuthManager($authenticators);
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