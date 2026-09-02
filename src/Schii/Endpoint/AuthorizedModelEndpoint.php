<?php

declare(strict_types=1);

namespace Phue\Schii\Endpoint;

use Phue\Auth\AuthManager;
use Phue\Auth\RbacManager;
use Phue\Core\Http\Response;
use Phue\Schii\Schema\SchemaRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Schii REST Endpoint decorator adding automatic RBAC checks via SchemaRegistry metadata.
 *
 * @package Phue\Schii\Endpoint
 */
final class AuthorizedModelEndpoint
{
    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private readonly AuthManager $auth,
        private readonly RbacManager $rbac
    ) {}

    public function handle(ServerRequestInterface $request, string $modelClass, string $action): ResponseInterface
    {
        $schema = $this->schemaRegistry->get($modelClass);
        $tableName = $schema->entity;
        
        $requiredPermission = "{$tableName}.{$action}";

        $user = $this->auth->getUser();
        if ($user === null || !$this->rbac->checkAccess($user->getId(), $requiredPermission)) {
            return Response::json(['error' => "Access Denied: Requires '{$requiredPermission}' permission."], 403);
        }

        return Response::json(['status' => 'success', 'action' => $action]);
    }
}