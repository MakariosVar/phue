<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Endpoint;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Schii\Schema\SchemaSyncEngine;

/**
 * Endpoint for inspecting schema diffs and executing structural synchronization.
 *
 * @package Phue\Modules\Schii\Endpoint
 */
final class SchemaSyncEndpoint
{
    /**
     * SchemaSyncEndpoint constructor.
     *
     * @param SchemaSyncEngine $syncEngine      Schema synchronization engine service.
     * @param string           $entityNamespace Base PHP namespace for dynamic model mapping.
     */
    public function __construct(
        private readonly SchemaSyncEngine $syncEngine,
        private readonly string $entityNamespace = "App\\Models",
    ) {}

    /**
     * Computes the schema diff plan or applies changes to the target database table.
     *
     * @route POST /api/schii/schema/{entity}/sync
     *
     * @param Request      $request Incoming HTTP request object.
     * @param array|string $params  Route parameters containing the target entity slug.
     *
     * @return Response JSON response containing dry-run diff plan or execution results.
     */
    public function sync(Request $request, array|string $params): Response
    {
        /** @var string $entity */
        $entity = is_array($params)
            ? $params['entity'] ?? (reset($params) ?: '')
            : $params;

        $modelClass = $this->resolveModelClass((string) $entity);
        if ($modelClass === null) {
            return Response::json(['error' => 'Resource not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = method_exists($request, 'getParsedBody')
            ? ($request->getParsedBody() ?? [])
            : $request->input();

        $apply = (bool) ($body['apply'] ?? false);
        $allowDrops = (bool) ($body['allowDrops'] ?? false);

        if ($apply) {
            $executedActions = $this->syncEngine->apply($modelClass, dryRun: false, allowDrops: $allowDrops);
            return Response::json([
                'success' => true,
                'executed' => $executedActions,
            ]);
        }

        $plan = $this->syncEngine->diff($modelClass);

        return Response::json($plan);
    }

    /**
     * Inspects and returns the schema diff array for the target entity.
     *
     * @route GET /api/schii/schema/{entity}/diff
     *
     * @param Request      $request Incoming HTTP request object.
     * @param array|string $params  Route parameters containing the target entity slug.
     *
     * @return Response JSON response containing the schema diff array.
     */
    public function diff(Request $request, array|string $params): Response
    {
        /** @var string $entity */
        $entity = is_array($params)
            ? $params['entity'] ?? (reset($params) ?: '')
            : $params;

        $modelClass = $this->resolveModelClass((string) $entity);
        if ($modelClass === null) {
            return Response::json(['error' => 'Resource not found'], 404);
        }

        $pdo = $this->syncEngine->getPdo();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?? 'sqlite';

        $schemaDiff = $this->syncEngine->diff($modelClass);

        return Response::json($schemaDiff->toArray((string) $driver));
    }

    /**
     * Resolves an entity slug to its corresponding ActiveRecord model class.
     *
     * @param string $entity Target entity slug.
     *
     * @return class-string<BaseActiveRecord>|null Class name or null if not resolved.
     */
    private function resolveModelClass(string $entity): ?string
    {
        $key = strtolower(trim($entity));
        $candidates = [$key];

        if (str_ends_with($key, 's') && strlen($key) > 1) {
            $candidates[] = substr($key, 0, -1);
        }

        $baseNamespace = rtrim($this->entityNamespace, '\\');

        foreach ($candidates as $candidate) {
            $studly = str_replace(
                ' ',
                '',
                ucwords(str_replace(['-', '_'], ' ', $candidate))
            );
            $targetClass = "{$baseNamespace}\\{$studly}";

            if (class_exists($targetClass) && is_subclass_of($targetClass, BaseActiveRecord::class)) {
                return $targetClass;
            }
        }

        return null;
    }
}