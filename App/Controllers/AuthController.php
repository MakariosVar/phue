<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Routing\Attribute\Route;

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
}
