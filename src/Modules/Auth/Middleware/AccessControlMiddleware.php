<?php

declare(strict_types=1);

namespace Phue\Modules\Auth\Middleware;

use function Phue\Core\t;

use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\RbacManager;
use Phue\Core\Request;
use Phue\Core\Response;

/**
 * Native Phue Middleware ensuring request identity possesses required permission.
 *
 * @package Phue\Modules\Auth\Middleware
 */
final class AccessControlMiddleware
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly RbacManager $rbac,
        private readonly string $requiredPermission
    ) {}

    /**
     * Handles authentication and permission checks natively via invokable interface.
     *
     * @param Request $request
     * @param callable(Request): Response $next
     * @return Response
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $user = $this->auth->getUser();

        if ($user === null) {
            return Response::json(['error' => t('Unauthorized access. Authentication required.')], 401);
        }

        if (!$this->rbac->checkAccess($user->getId(), $this->requiredPermission)) {
            return Response::json(['error' => t('Forbidden. Insufficient permissions.')], 403);
        }

        return $next($request);
    }
}
