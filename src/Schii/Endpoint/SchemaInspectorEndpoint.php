<?php

declare(strict_types=1);

namespace Phue\Schii\Endpoint;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Schii\Schema\SchemaInspector;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Schii\Schema\SchemaRegistry;

/**
 * Endpoint for inspecting the physical database table structure of a model schema entity.
 *
 * @package Phue\Schii\Endpoint
 */
final class SchemaInspectorEndpoint
{
    /**
     * SchemaInspectorEndpoint constructor.
     *
     * @param SchemaRegistry   $schemaRegistry Schema definitions registry.
     * @param SchemaInspector  $schemaInspector Database inspector service.
     * @param Connection       $connection      Active database connection instance.
     * @param string           $entityNamespace Base PHP namespace for dynamic model mapping.
     */
    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private readonly SchemaInspector $schemaInspector,
        private readonly Connection $connection,
        private readonly string $entityNamespace = "App\\Models",
    ) {}

    /**
     * Returns the column definitions of the database table associated with the specified entity.
     *
     * @route GET /api/schii/schema/{entity}/structure
     *
     * @param Request      $request Incoming HTTP request object.
     * @param array|string $params  Route parameters containing the target entity slug.
     *
     * @return Response JSON response containing table name and array of column definitions.
     */
    public function structure(Request $request, array|string $params): Response
    {
        /** @var string $entity */
        $entity = is_array($params)
            ? $params['entity'] ?? (reset($params) ?: '')
            : $params;

        $modelClass = $this->resolveModelClass((string) $entity);
        if ($modelClass === null) {
            return Response::json(['error' => 'Resource not found'], 404);
        }

        /** @var BaseActiveRecord $instance */
        $instance = new $modelClass();
        $tableName = $instance->getTable();

        $columns = $this->schemaInspector->inspect($tableName);

        $columnsData = array_map(
            static fn($col) => method_exists($col, 'toArray') ? $col->toArray() : (array) $col,
            $columns
        );

        return Response::json([
            'table' => $tableName,
            'columns' => $columnsData,
        ]);
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