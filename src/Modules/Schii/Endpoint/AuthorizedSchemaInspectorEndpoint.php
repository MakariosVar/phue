<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Endpoint;

use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\RbacManager;

/**
 * Class AuthorizedSchemaInspectorEndpoint
 *
 * Decorator for SchemaInspectorEndpoint enforcing authentication and RBAC checks.
 *
 * @package Phue\Modules\Schii\Endpoint
 */
class AuthorizedSchemaInspectorEndpoint
{
    /**
     * AuthorizedSchemaInspectorEndpoint constructor.
     *
     * @param SchemaInspectorEndpoint $delegate Underlying schema inspector endpoint.
     * @param AuthManager $authManager Authentication manager.
     * @param RbacManager $rbacManager RBAC permission manager.
     */
    public function __construct(
        private SchemaInspectorEndpoint $delegate,
        private AuthManager $authManager,
        private RbacManager $rbacManager
    ) {}

    /**
     * Authorizes and delegates schema structure inspection.
     *
     * @param Request      $request Current HTTP request.
     * @param array|string $params  Route parameters containing the target entity slug.
     * @return Response
     */
    public function structure(Request $request, array|string $params): Response
    {
        $entity = is_array($params)
            ? ($params['entity'] ?? (reset($params) ?: ''))
            : $params;

        $user = $this->authManager->authenticate($request);

        if ($user === null) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $permission = "admin.{$entity}.read";

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

        return $this->delegate->structure($request, $params);
    }
}