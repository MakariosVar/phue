<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Endpoint;

use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\RbacManager;

/**
 * Class AuthorizedSchemaSyncEndpoint
 *
 * Decorator for SchemaSyncEndpoint enforcing authentication and RBAC checks.
 *
 * @package Phue\Modules\Schii\Endpoint
 */
class AuthorizedSchemaSyncEndpoint
{
    /**
     * AuthorizedSchemaSyncEndpoint constructor.
     *
     * @param SchemaSyncEndpoint $delegate Underlying schema sync endpoint.
     * @param AuthManager $authManager Authentication manager.
     * @param RbacManager $rbacManager RBAC permission manager.
     */
    public function __construct(
        private SchemaSyncEndpoint $delegate,
        private AuthManager $authManager,
        private RbacManager $rbacManager
    ) {}

    /**
     * Authorizes and delegates schema synchronization.
     *
     * @param Request      $request Current HTTP request.
     * @param array|string $params  Route parameters containing the target entity slug.
     * @return Response
     */
    public function sync(Request $request, array|string $params): Response
    {
        return $this->authorizeAndDelegate(
            $request,
            $params,
            'update',
            fn() => $this->delegate->sync($request, $params)
        );
    }

    /**
     * Authorizes and delegates schema diff analysis.
     *
     * @param Request      $request Current HTTP request.
     * @param array|string $params  Route parameters containing the target entity slug.
     * @return Response
     */
    public function diff(Request $request, array|string $params): Response
    {
        return $this->authorizeAndDelegate(
            $request,
            $params,
            'read',
            fn() => $this->delegate->diff($request, $params)
        );
    }

    /**
     * Performs authentication and permission checks before executing the delegate operation.
     *
     * @param Request      $request      Current HTTP request.
     * @param array|string $params       Route parameters containing the target entity slug.
     * @param string       $action       Required permission action verb.
     * @param callable     $delegateCall Operation callback.
     * @return Response
     */
    private function authorizeAndDelegate(
        Request $request,
        array|string $params,
        string $action,
        callable $delegateCall
    ): Response {
        $user = $this->authManager->authenticate($request);

        if ($user === null) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $entity = is_array($params)
            ? ($params['entity'] ?? (reset($params) ?: ''))
            : $params;

        $permission = "admin.{$entity}.{$action}";

        if (!$this->rbacManager->checkAccess($user->getId(), $permission, $user->getRoles())) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        return $delegateCall();
    }
}