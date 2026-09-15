<?php

declare(strict_types=1);

namespace Phue\Schii\Endpoint;

use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Schii\Audit\SchiiAudit;

/**
 * Endpoint for querying Schii audit logs.
 *
 * Required permission: admin.audit.read
 *
 * @package Phue\Schii\Endpoint
 */
final class AuditEndpoint
{
    /**
     * Retrieves audit logs with optional query parameters.
     *
     * @route GET /api/schii/audit
     *
     * @param Request $request Incoming HTTP request object.
     *
     * @return Response JSON response containing filtered audit logs.
     */
    public function index(Request $request): Response
    {
        $queryParams = method_exists($request, 'getQueryParams')
            ? $request->getQueryParams()
            : $_GET;

        $entity = isset($queryParams['entity']) ? (string) $queryParams['entity'] : null;
        $verb = isset($queryParams['verb']) ? (string) $queryParams['verb'] : null;
        $user = isset($queryParams['user']) ? (string) $queryParams['user'] : null;
        
        $limitInput = isset($queryParams['limit']) ? (int) $queryParams['limit'] : 50;
        $limit = max(0, min(200, $limitInput));

        $logs = SchiiAudit::getLogs($entity, $verb, $user, $limit);

        return Response::json([
            'success' => true,
            'count' => count($logs),
            'logs' => $logs,
        ]);
    }

    /**
     * Retrieves audit logs filtered specifically by a target entity.
     *
     * @route GET /api/schii/audit/{entity}
     *
     * @param Request      $request Incoming HTTP request object.
     * @param array|string $params  Route parameters containing the target entity slug.
     *
     * @return Response JSON response containing entity audit logs.
     */
    public function forEntity(Request $request, array|string $params): Response
    {
        /** @var string $entity */
        $entity = is_array($params)
            ? $params['entity'] ?? (reset($params) ?: '')
            : $params;

        $queryParams = method_exists($request, 'getQueryParams')
            ? $request->getQueryParams()
            : $_GET;

        $limitInput = isset($queryParams['limit']) ? (int) $queryParams['limit'] : 50;
        $limit = max(0, min(200, $limitInput));

        $logs = SchiiAudit::getLogs(entity: trim($entity), limit: $limit);

        return Response::json([
            'success' => true,
            'entity' => $entity,
            'count' => count($logs),
            'logs' => $logs,
        ]);
    }
}