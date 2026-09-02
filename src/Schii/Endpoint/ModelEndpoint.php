<?php

declare(strict_types=1);

namespace Phue\Schii\Endpoint;

use Phue\Core\Database\ActiveRecord\ActiveQuery;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Schii\Schema\SchemaRegistry;

/**
 * Handles automatic REST API endpoints for Schii-annotated models.
 */
final class ModelEndpoint
{
    /**
     * @param SchemaRegistry $schemaRegistry Registry that parses model reflection data.
     * @param array<string, class-string<BaseActiveRecord>> $entityMapping Map of entity names to ActiveRecord class paths.
     */
    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private array $entityMapping = []
    ) {}

    /**
     * Register an entity name with its corresponding ActiveRecord class.
     * Example: 'products' => \App\Models\Product::class
     *
     * @param string $entity The URL slug/identifier for the entity.
     * @param class-string<BaseActiveRecord> $modelClass The full class name.
     */
    public function registerEntity(string $entity, string $modelClass): void
    {
        $this->entityMapping[strtolower($entity)] = $modelClass;
    }

    /**
     * GET /schii/schema/{entity}
     * Returns the Schii JSON Schema Protocol for UI consumption.
     */
    public function schema(string $entity): Response
    {
        $modelClass = $this->resolveModelClass($entity);
        $schema = $this->schemaRegistry->get($modelClass);

        return Response::json($schema->toArray());
    }

    /**
     * GET /schii/{entity}
     * Smart List Endpoint supporting search, filtering, sorting, and pagination.
     */
    public function index(string $entity, Request $request): Response
    {
        $modelClass = $this->resolveModelClass($entity);
        $schema = $this->schemaRegistry->get($modelClass);

        /** @var ActiveQuery $query */
        $query = $modelClass::query();

        // 1. Global Search across searchable fields
        $searchQuery = $request->input('q') ?? $request->getQuery('q');
        if (!empty($searchQuery)) {
            $searchableFields = array_values(array_filter(
                $schema->fields,
                static fn ($field) => $field->searchable
            ));

            foreach ($searchableFields as $index => $field) {
                if ($index === 0) {
                    $query->where($field->name, 'LIKE', "%{$searchQuery}%");
                } else {
                    $query->orWhere($field->name, 'LIKE', "%{$searchQuery}%");
                }
            }
        }

        // 2. Dynamic Filtering across filterable fields
        foreach ($schema->fields as $field) {
            if ($field->filterable) {
                $value = $request->input($field->name) ?? $request->getQuery($field->name);
                if ($value !== '' && $value !== null) {
                    $query->where($field->name, '=', $value);
                }
            }
        }

        // 3. Dynamic Sorting across sortable fields
        $sort = $request->input('sort') ?? $request->getQuery('sort');
        $direction = strtoupper((string) ($request->input('direction') ?? $request->getQuery('direction') ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        if ($sort && isset($schema->fields[$sort]) && $schema->fields[$sort]->sortable) {
            $query->orderBy($sort, $direction);
        }

        // 4. Pagination
        $page = max(1, (int) ($request->input('page') ?? $request->getQuery('page') ?? 1));
        $limit = max(1, min(100, (int) ($request->input('limit') ?? $request->getQuery('limit') ?? 25)));
        $total = (int) (clone $query)->count();

        $items = $query->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        return Response::json([
            'data' => array_map(static fn (BaseActiveRecord $item) => $item->toArray(), $items),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'last_page' => (int) ceil($total / $limit),
            ],
        ]);
    }

    /**
     * GET /schii/{entity}/{id}
     * Retrieve a single record by primary key.
     */
    public function show(string $entity, mixed $id): Response
    {
        $modelClass = $this->resolveModelClass($entity);
        $model = $modelClass::find($id);

        if (!$model) {
            return Response::json(['error' => 'Resource not found'], 404);
        }

        return Response::json(['data' => $model->toArray()]);
    }

    /**
     * POST /schii/{entity}
     * Create a new record.
     */
    public function store(string $entity, Request $request): Response
    {
        $modelClass = $this->resolveModelClass($entity);
        
        /** @var BaseActiveRecord $model */
        $model = new $modelClass();
        $model->fill($this->extractInputData($request));

        if (!$model->save()) {
            return Response::json([
                'error' => 'Validation failed',
                'errors' => $model->getErrors(),
            ], 422);
        }

        return Response::json(['data' => $model->toArray()], 201);
    }

    /**
     * PUT /schii/{entity}/{id}
     * Update an existing record.
     */
    public function update(string $entity, mixed $id, Request $request): Response
    {
        $modelClass = $this->resolveModelClass($entity);
        $model = $modelClass::find($id);

        if (!$model) {
            return Response::json(['error' => 'Resource not found'], 404);
        }

        $model->fill($this->extractInputData($request));

        if (!$model->save()) {
            return Response::json([
                'error' => 'Validation failed',
                'errors' => $model->getErrors(),
            ], 422);
        }

        return Response::json(['data' => $model->toArray()]);
    }

    /**
     * DELETE /schii/{entity}/{id}
     * Delete a record by primary key.
     */
    public function delete(string $entity, mixed $id): Response
    {
        $modelClass = $this->resolveModelClass($entity);
        $model = $modelClass::find($id);

        if (!$model) {
            return Response::json(['error' => 'Resource not found'], 404);
        }

        if (!$model->delete()) {
            return Response::json(['error' => 'Failed to delete resource'], 500);
        }

        return Response::json(['message' => 'Resource deleted successfully']);
    }

    /**
     * Safely extract all input data from the Request object.
     *
     * @return array<string, mixed>
     */
    private function extractInputData(Request $request): array
    {
        if (method_exists($request, 'getParsedBody')) {
            return array_merge($request->getQueryParams(), $request->getParsedBody());
        }

        return array_merge($request->getQueryParams(), $request->input());
    }

    /**
     * Resolve the string entity slug to a fully qualified class name.
     *
     * @return class-string<BaseActiveRecord>
     */
    private function resolveModelClass(string $entity): string
    {
        $key = strtolower($entity);

        if (!isset($this->entityMapping[$key])) {
            throw new \InvalidArgumentException("Unregistered Schii Entity: {$entity}");
        }

        return $this->entityMapping[$key];
    }
}