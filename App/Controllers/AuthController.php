<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Routing\Attribute\Route;
use Phue\Modules\Auth\Jwt\Jwt;
use Phue\Modules\Auth\Jwt\JwtConfig;
use Phue\Core\Config;
use Phue\Core\Security\Attributes\CsrfExempt;
use Phue\Modules\RateLimit\Attribute\RateLimit;

/**
 * AuthController handles authentication routes and session management.
 *
 * @package App\Controllers
 */
#[Route('/')]
class AuthController extends Controller
{
    #[Route('/login', methods: ['GET'])]
    public function showLogin(Request $request): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // If already logged in, go directly to Schii
        if (!empty($_SESSION['__user_id'])) {
            return new Response('', 302, ['Location' => '/schii']);
        }

        return $this->ui->render('Pages/Login', [
            'error' => null,
        ]);
    }

    #[Route('/login', methods: ['POST'])]
    public function login(Request $request): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $username = trim((string) ($request->input('username') ?? ''));
        $password = (string) ($request->input('password') ?? '');

        if ($username === '' || $password === '') {
            if ($request->isXmlHttpRequest() || str_contains((string) $request->getHeader('Accept', ''), 'application/json')) {
                return Response::json(['error' => 'Username and password are required.'], 422);
            }
            return $this->ui->render('Pages/Login', [
                'error' => 'Username and password are required.',
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->where('username', '=', $username)->first();

        if (!$user || !$user->validatePassword($password)) {
            if ($request->isXmlHttpRequest() || str_contains((string) $request->getHeader('Accept', ''), 'application/json')) {
                return Response::json(['error' => 'Invalid username or password.'], 401);
            }
            return $this->ui->render('Pages/Login', [
                'error' => 'Invalid username or password.',
            ]);
        }

        // Success login: save user id in session
        $_SESSION['__user_id'] = $user->getId();

        if ($request->isXmlHttpRequest() || str_contains((string) $request->getHeader('Accept', ''), 'application/json')) {
            return Response::json(['success' => true, 'redirect' => '/schii']);
        }

        return new Response('', 302, ['Location' => '/schii']);
    }

    /**
     * Logout is POST-only.
     *
     * With GET accepted, any image tag or link on any site could sign the user out. The
     * impact is low, but it is a real cross-site request doing a real state change, which is
     * exactly what CSRF protection exists to stop, and the CSRF middleware exempts safe
     * methods — so leaving GET here would exempt logout from the very control it should
     * sit behind. A GET must never change state.
     */
    #[Route('/logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        unset($_SESSION['__user_id']);
        session_destroy();

        if ($request->isXmlHttpRequest() || str_contains((string) $request->getHeader('Accept', ''), 'application/json')) {
            return Response::json(['success' => true, 'redirect' => '/login']);
        }

        return new Response('', 302, ['Location' => '/login']);
    }

    #[Route('/jwt', methods: ['POST'])]
    #[RateLimit(requests: 10, window: 'minute')]
    #[CsrfExempt]
    public function jwt(Request $request): Response
    {
        $data = $request->getParsedBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        // Find and validate user
        $user = User::findByUsername($username);
        if (!$user || !$user->validatePassword($password)) {
            return Response::json(['error' => 'Invalid username or password.'], 401);
        }

        // Get JWT config from app config
        /** @var Config $appConfig */
        $appConfig = $this->container->get(Config::class);
        $jwtConfig = $appConfig->get('auth.jwt', []);

        if (empty($jwtConfig['secret'])) {
            return Response::json(['error' => 'JWT not configured.'], 500);
        }

        // Generate JWT token
        $config = new JwtConfig($jwtConfig['secret'], $jwtConfig);
        $token = Jwt::encode([
            'sub' => $user->getAuthKey() ?? (string) $user->getId(),
            'name' => $user->username,
            'roles' => $user->getRoles(),
        ], $config);

        return Response::json([
            'token' => $token,
            'expires_in' => $jwtConfig['ttl'] ?? 3600,
        ]);
    }
}
