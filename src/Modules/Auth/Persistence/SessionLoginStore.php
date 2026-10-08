<?php

declare(strict_types=1);

namespace Phue\Modules\Auth\Persistence;

/**
 * Class SessionLoginStore
 * 
 * Implementation of LoginStoreInterface that directly manages 
 * the `$_SESSION` array while enforcing the session status guard.
 * 
 * @package Phue\Modules\Auth\Persistence
 */
class SessionLoginStore implements LoginStoreInterface
{
    private const SESSION_KEY = '__user_id';

    /**
     * {@inheritdoc}
     */
    public function getId(): int|string|null
    {
        $this->ensureSessionActive();

        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function store(int|string $id): void
    {
        $this->ensureSessionActive();

        // Regenerate on privilege change, not only on logout.
        //
        // Without this, an attacker who can plant a known session id in the victim's
        // browser before login keeps a valid handle afterwards: that is session fixation,
        // and the login the victim performs hands the attacker an authenticated session
        // without any credential ever being stolen. clear() already regenerates; login is
        // the moment the identity actually changes, so that is where it has to happen too.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = $id;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->ensureSessionActive();

        unset($_SESSION[self::SESSION_KEY]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Checks if the session is active and starts it if not
     * (session_start guard, mirroring the SessionAuthenticator logic).
     *
     * The cookie parameters are set on every start, not once at boot, because
     * session_set_cookie_params() only affects the session being started and there is no
     * earlier moment in this class that could set them: the session is created lazily on
     * the first read as often as on the first write. SameSite=Lax here is defence in depth
     * under the CSRF token, not a substitute for it — Lax does nothing across subdomains
     * and is ignored by older clients.
     *
     * @return void
     */
    private function ensureSessionActive(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_start();
        }
    }

    /**
     * Whether the current request reached PHP over HTTPS.
     *
     * Deliberately does not consult X-Forwarded-Proto: that header is attacker-controlled
     * unless a proxy list says otherwise, and marking a session cookie Secure based on it
     * would be a downgrade to a plaintext cookie rather than a hardening. Terminating
     * proxies should set HTTPS in the environment; if that is not happening the deployment
     * is already wrong in a way this method cannot fix from here.
     *
     * @return bool
     */
    private static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }
}