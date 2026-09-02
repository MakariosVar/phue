<?php

declare(strict_types=1);

namespace Phue\Auth\Middleware;

use Phue\Auth\AuthManager;
use Phue\Auth\RbacManager;
use Phue\Core\Request;
use Phue\Core\Response;

/**
 * Native Phue Middleware ensuring request identity possesses required permission.
 *
 * @package Phue\Auth\Middleware
 */
final class AccessControlMiddleware
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly RbacManager $rbac,
        private readonly string $requiredPermission
    ) {}

    /**
     * Handles authentication and permission checks natively.
     *
     * @param Request $request
     * @param callable(Request): Response $next
     * @return Response
     */
    public function handle(Request $request, callable $next): Response
    {
        $user = $this->auth->getUser();

        if ($user === null) {
            return Response::json(['error' => 'Unauthorized access. Authentication required.'], 401);
        }

        if (!$this->rbac->checkAccess($user->getId(), $this->requiredPermission)) {
            return Response::json(['error' => 'Forbidden. Insufficient permissions.'], 403);
        }

        return $next($request);
    }
}