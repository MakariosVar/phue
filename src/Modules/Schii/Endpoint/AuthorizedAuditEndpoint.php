<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Endpoint;

use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\RbacManager;
use Phue\Core\Request;
use Phue\Core\Response;

/**
 * Class AuthorizedAuditEndpoint
 *
 * Authorization decorator surrounding AuditEndpoint operations.
 *
 * @package Phue\Modules\Schii\Endpoint
 */
class AuthorizedAuditEndpoint
{
    public function __construct(
        protected AuditEndpoint $delegate,
        protected AuthManager $authManager,
        protected RbacManager $rbacManager
    ) {}

    public function index(Request $request): Response
    {
        return $this->authorizeAndDelegate($request, fn() => $this->delegate->index($request));
    }

    public function forEntity(Request $request, array|string $params): Response
    {
        return $this->authorizeAndDelegate($request, fn() => $this->delegate->forEntity($request, $params));
    }

    protected function authorizeAndDelegate(Request $request, callable $delegateCall): Response
    {
        $user = $this->authManager->authenticate($request);

        if (!$user) {
            return new Response(
                json_encode([
                    'error'   => 'Unauthorized',
                    'message' => 'Authentication required.'
                ], JSON_THROW_ON_ERROR),
                401,
                ['Content-Type' => 'application/json']
            );
        }

        $permission = 'admin.audit.read';

        if (!$this->rbacManager->checkAccess($user->getId(), $permission, $user->getRoles())) {
            return new Response(
                json_encode([
                    'error'   => 'Forbidden',
                    'message' => "Access Denied: Requires '{$permission}'"
                ], JSON_THROW_ON_ERROR),
                403,
                ['Content-Type' => 'application/json']
            );
        }

        return $delegateCall();
    }
}