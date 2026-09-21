<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Endpoint;

use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\RbacManager;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Schii\Schema\SchemaRegistry;

/**
 * Class AuthorizedModelEndpoint
 *
 * Authorization decorator surrounding core ModelEndpoint operations.
 * Intercepts incoming CRUD requests, checks user authentication via AuthManager,
 * normalizes action verbs and entities via SchemaRegistry to permission strings,
 * checks RBAC access, and delegates execution to ModelEndpoint.
 *
 * @package Phue\Modules\Schii\Endpoint
 */
class AuthorizedModelEndpoint
{
    /**
     * AuthorizedModelEndpoint constructor.
     *
     * @param ModelEndpoint $delegate Underlying endpoint execution class.
     * @param AuthManager $authManager Authentication manager instance.
     * @param RbacManager $rbacManager RBAC permission evaluation manager instance.
     * @param SchemaRegistry $schemaRegistry Entity schema registry instance.
     */
    public function __construct(
        protected ModelEndpoint $delegate,
        protected AuthManager $authManager,
        protected RbacManager $rbacManager,
        protected SchemaRegistry $schemaRegistry
    ) {}

    /**
     * Handles entity index list requests with RBAC read permission check.
     *
     * @param Request $request Framework Request instance.
     * @param array<string, mixed> $params Route parameters containing target entity.
     * @return Response HTTP Response from delegate or access error response.
     */
    public function index(Request $request, array $params): Response
    {
        return $this->authorizeAndDelegate($request, $params, 'list', fn() => $this->delegate->index($request, $params));
    }

    /**
     * Handles single entity view requests with RBAC read permission check.
     *
     * @param Request $request Framework Request instance.
     * @param array<string, mixed> $params Route parameters containing entity name and identifier.
     * @return Response HTTP Response from delegate or access error response.
     */
    public function show(Request $request, array $params): Response
    {
        return $this->authorizeAndDelegate($request, $params, 'show', fn() => $this->delegate->show($request, $params));
    }

    /**
     * Handles entity creation requests with RBAC create permission check.
     *
     * @param Request $request Framework Request instance.
     * @param array<string, mixed> $params Route parameters containing target entity.
     * @return Response HTTP Response from delegate or access error response.
     */
    public function store(Request $request, array $params): Response
    {
        return $this->authorizeAndDelegate($request, $params, 'store', fn() => $this->delegate->store($request, $params));
    }

    /**
     * Handles entity update requests with RBAC update permission check.
     *
     * @param Request $request Framework Request instance.
     * @param array<string, mixed> $params Route parameters containing entity name and identifier.
     * @return Response HTTP Response from delegate or access error response.
     */
    public function update(Request $request, array $params): Response
    {
        return $this->authorizeAndDelegate($request, $params, 'update', fn() => $this->delegate->update($request, $params));
    }

    /**
     * Handles entity deletion requests with RBAC delete permission check.
     *
     * @param Request $request Framework Request instance.
     * @param array<string, mixed> $params Route parameters containing entity name and identifier.
     * @return Response HTTP Response from delegate or access error response.
     */
    public function delete(Request $request, array $params): Response
    {
        return $this->authorizeAndDelegate($request, $params, 'delete', fn() => $this->delegate->delete($request, $params));
    }

    /**
     * Handles bulk entity operations with RBAC bulk permission check.
     *
     * @param Request $request Framework Request instance.
     * @param array<string, mixed> $params Route parameters containing target entity.
     * @return Response HTTP Response from delegate or access error response.
     */
    public function bulk(Request $request, array $params): Response
    {
        return $this->authorizeAndDelegate($request, $params, 'bulk', fn() => $this->delegate->bulk($request, $params));
    }

    /**
     * Handles entity export requests with RBAC export permission check.
     *
     * @param Request $request Framework Request instance.
     * @param array<string, mixed> $params Route parameters containing target entity.
     * @return Response HTTP Response from delegate or access error response.
     */
    public function export(Request $request, array $params): Response
    {
        return $this->authorizeAndDelegate($request, $params, 'export', fn() => $this->delegate->export($request, $params));
    }

    /**
     * Handles entity import requests with RBAC import permission check.
     *
     * @param Request $request Framework Request instance.
     * @param array<string, mixed> $params Route parameters containing target entity.
     * @return Response HTTP Response from delegate or access error response.
     */
    public function import(Request $request, array $params): Response
    {
        return $this->authorizeAndDelegate($request, $params, 'import', fn() => $this->delegate->import($request, $params));
    }

    /**
     * Evaluates 401/403 authorization logic before invoking target delegate callback.
     *
     * @param Request $request Framework Request instance.
     * @param array<string, mixed> $params Route parameters containing target entity.
     * @param string $rawAction CRUD operation verb (e.g., 'list', 'store', 'update', 'delete').
     * @param callable(): Response $delegateCall Callback delegating to target ModelEndpoint method.
     * @return Response 401 Unauthorized, 403 Forbidden, or delegate Response.
     */
    protected function authorizeAndDelegate(Request $request, array $params, string $rawAction, callable $delegateCall): Response
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

        $rawEntity = $params['entity'] ?? '';
        $entity = $this->resolveEntityShortClass($rawEntity);
        $action = $this->normalizeActionPermission($rawAction);

        $permission = "admin.{$entity}.{$action}";

        // Pass both user ID and explicit user roles from IdentityInterface
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

    /**
     * Resolves the entity short class name using SchemaRegistry or fallback singularization.
     *
     * @param string $entity Raw entity string from route parameters.
     * @return string Resolved short class name / entity slug.
     */
    protected function resolveEntityShortClass(string $entity): string
    {

        // Attempt to fetch schema info to match registered ShortClass if available
        $schema = $this->schemaRegistry->getSchemaBySlug($entity);
        if ($schema && method_exists($schema, 'getShortClassName')) {
            return $schema->getShortClassName();
        }
        $singular = $this->normalizeEntitySingular($entity);

        return ucfirst($singular);
    }

    /**
     * Converts plural entity slugs into singular strings, handling irregular exceptions (e.g., 'status', 'news').
     *
     * @param string $entity Raw entity string from route parameters.
     * @return string Normalized singular entity name.
     */
    protected function normalizeEntitySingular(string $entity): string
    {
        $entity = strtolower(trim($entity));
        
        // Exceptions where trailing 's' is part of the word
        $uncountableExceptions = ['status', 'news', 'address', 'series', 'species'];
        if (in_array($entity, $uncountableExceptions, true)) {
            return $entity;
        }

        if (str_ends_with($entity, 'ies')) {
            return substr($entity, 0, -3) . 'y';
        }
        if (str_ends_with($entity, 's') && !str_ends_with($entity, 'ss')) {
            return substr($entity, 0, -1);
        }
        return $entity;
    }

    /**
     * Normalizes internal action names to standard RBAC permission verbs ('create', 'read', 'update', 'delete').
     *
     * @param string $action Internal CRUD action name.
     * @return string Mapped permission verb string.
     */
    protected function normalizeActionPermission(string $action): string
    {
        return match (strtolower($action)) {
            'store', 'create' => 'create',
            'list', 'show', 'index', 'view' => 'read',
            'update', 'edit' => 'update',
            'delete', 'destroy' => 'delete',
            default => $action,
        };
    }
}