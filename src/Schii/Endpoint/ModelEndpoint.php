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
        private array $entityMapping = [],
    ) {}

    /**
     * Register an entity name with its corresponding ActiveRecord class.
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
     */
    public function schema(Request $request, array|string $params): Response
    {
        $entity = is_array($params) ? $params["entity"] ?? reset($params) : $params;
        $modelClass = $this->resolveModelClass((string) $entity);

        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        $schema = $this->schemaRegistry->get($modelClass);

        return Response::json($schema->toArray());
    }

    /**
     * GET /schii/{entity}
     */
    public function index(Request $request, array|string $params): Response
    {
        $entity = is_array($params) ? $params["entity"] ?? reset($params) : $params;
        $modelClass = $this->resolveModelClass((string) $entity);

        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        $schema = $this->schemaRegistry->get($modelClass);

        /** @var ActiveQuery $query */
        $query = $modelClass::query();

        // 1. Global Search with LIKE wildcard escaping
        $searchQuery = $request->input("q") ?? $request->getQuery("q");
        if (!empty($searchQuery)) {
            $escapedQuery = addcslashes((string) $searchQuery, "%_\\");
            $searchableFields = array_values(
                array_filter(
                    $schema->fields,
                    static fn($field) => $field->searchable,
                ),
            );

            foreach ($searchableFields as $index => $field) {
                if ($index === 0) {
                    $query->where($field->name, "LIKE", "%{$escapedQuery}%");
                } else {
                    $query->orWhere($field->name, "LIKE", "%{$escapedQuery}%");
                }
            }
        }

        // 2. Dynamic Filtering across filterable fields
        foreach ($schema->fields as $field) {
            if ($field->filterable) {
                $value = $request->input($field->name) ?? $request->getQuery($field->name);
                if ($value !== "" && $value !== null) {
                    $query->where($field->name, "=", $value);
                }
            }
        }

        // 3. Dynamic Sorting
        $sort = $request->input("sort") ?? $request->getQuery("sort");
        $direction = strtoupper((string) ($request->input("direction") ?? ($request->getQuery("direction") ?? "ASC"))) === "DESC" ? "DESC" : "ASC";

        if ($sort && isset($schema->fields[$sort]) && $schema->fields[$sort]->sortable) {
            $query->orderBy($sort, $direction);
        }

        // 4. Pagination
        $page = max(1, (int) ($request->input("page") ?? ($request->getQuery("page") ?? 1)));
        $limit = max(1, min(100, (int) ($request->input("limit") ?? ($request->getQuery("limit") ?? 25))));
        $total = (int) (clone $query)->count();

        $items = $query->offset(($page - 1) * $limit)->limit($limit)->get();

        return Response::json([
            "data" => array_map(static fn(BaseActiveRecord $item) => $item->toArray(), $items),
            "meta" => [
                "total" => $total,
                "page" => $page,
                "limit" => $limit,
                "last_page" => (int) ceil($total / $limit),
            ],
        ]);
    }

    /**
     * GET /schii/{entity}/{id}
     */
    public function show(Request $request, array $params): Response
    {
        $entity = $params["entity"] ?? null;
        $id = $params["id"] ?? null;

        $modelClass = $this->resolveModelClass((string) $entity);
        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        $model = $modelClass::find($id);
        if (!$model) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        return Response::json(["data" => $model->toArray()]);
    }

    /**
     * POST /schii/{entity}
     */
    public function store(Request $request, array|string $params): Response
    {
        $entity = is_array($params) ? $params["entity"] ?? reset($params) : $params;
        $modelClass = $this->resolveModelClass((string) $entity);

        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        $rawBody = method_exists($request, "getParsedBody")
            ? array_merge($request->getQueryParams(), $request->getParsedBody())
            : array_merge($request->getQueryParams(), $request->input());

        // Run validation against schema rules and unknown fields
        $errors = $this->validateData($rawBody, $modelClass, isUpdate: false);
        if (!empty($errors)) {
            return Response::json([
                "error" => "Validation failed",
                "errors" => $errors,
            ], 422);
        }

        $inputData = $this->extractInputData($rawBody, $modelClass);

        /** @var BaseActiveRecord $model */
        $model = new $modelClass();
        $model->fill($inputData);

        if (!$model->save()) {
            return Response::json([
                "error" => "Validation failed",
                "errors" => $model->getErrors(),
            ], 422);
        }

        return Response::json(["data" => $model->toArray()], 201);
    }

    /**
     * PUT /schii/{entity}/{id}
     */
    public function update(Request $request, array $params): Response
    {
        $entity = $params["entity"] ?? null;
        $id = $params["id"] ?? null;

        $modelClass = $this->resolveModelClass((string) $entity);
        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        $model = $modelClass::find($id);
        if (!$model) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        $rawBody = method_exists($request, "getParsedBody")
            ? array_merge($request->getQueryParams(), $request->getParsedBody())
            : array_merge($request->getQueryParams(), $request->input());

        // Run validation against schema rules and unknown fields
        $errors = $this->validateData($rawBody, $modelClass, isUpdate: true);
        if (!empty($errors)) {
            return Response::json([
                "error" => "Validation failed",
                "errors" => $errors,
            ], 422);
        }

        $inputData = $this->extractInputData($rawBody, $modelClass);

        $model->fill($inputData);

        if (!$model->save()) {
            return Response::json([
                "error" => "Validation failed",
                "errors" => $model->getErrors(),
            ], 422);
        }

        return Response::json(["data" => $model->toArray()]);
    }

    /**
     * DELETE /schii/{entity}/{id}
     */
    public function delete(Request $request, array $params): Response
    {
        $entity = $params["entity"] ?? null;
        $id = $params["id"] ?? null;

        $modelClass = $this->resolveModelClass((string) $entity);
        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        $model = $modelClass::find($id);
        if (!$model) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        if (!$model->delete()) {
            return Response::json(["error" => "Failed to delete resource"], 500);
        }

        return Response::json(["message" => "Resource deleted successfully"]);
    }

    /**
     * Extracts allowed input data, whitelisting schema fields and protecting primary keys.
     *
     * @return array<string, mixed>
     */
    private function extractInputData(array $data, string $modelClass): array
    {
        $schema = $this->schemaRegistry->get($modelClass);
        $allowedFields = array_keys($schema->fields);

        $filteredData = array_intersect_key($data, array_flip($allowedFields));

        /** @var BaseActiveRecord $tempModel */
        $tempModel = new $modelClass();
        $pk = $tempModel->getPrimaryKey();

        unset($filteredData[$pk]);

        return $filteredData;
    }

    /**
     * Validates input data against Schema Field attributes, rules and unknown fields.
     *
     * @return array<string, string>
     */
    private function validateData(array $data, string $modelClass, bool $isUpdate = false): array
    {
        $errors = [];
        $schema = $this->schemaRegistry->get($modelClass);
        $allowedFields = array_keys($schema->fields);

        /** @var BaseActiveRecord $tempModel */
        $tempModel = new $modelClass();
        $pk = $tempModel->getPrimaryKey();

        // 1. Strict Check for Unknown Fields
        foreach (array_keys($data) as $field) {
            if ($field === $pk) {
                continue; // Allow PK in body, it gets stripped safely later
            }
            if (!in_array($field, $allowedFields, true)) {
                $errors[$field] = "Unknown field '{$field}' is not allowed";
            }
        }

        // 2. Validate Known Fields & Schema Rules
        foreach ($schema->fields as $fieldName => $fieldSchema) {
            $value = $data[$fieldName] ?? null;

            // Check required fields
            if (!$isUpdate && $fieldSchema->required && ($value === null || $value === '')) {
                $errors[$fieldName] = "Field '{$fieldName}' is required";
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            // Combine rules from attributes
            $rules = $fieldSchema->rules;
            if ($fieldSchema->type === 'number' || $fieldSchema->type === 'numeric') {
                $rules[] = 'numeric';
            }

            foreach ($rules as $rule) {
                if ($rule === 'numeric' && !is_numeric($value)) {
                    $errors[$fieldName] = "Field '{$fieldName}' must be numeric";
                }
                if ($rule === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $errors[$fieldName] = "Field '{$fieldName}' must be an integer";
                }
                if ($rule === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[$fieldName] = "Field '{$fieldName}' must be a valid email";
                }
            }
        }

        return $errors;
    }

    /**
     * Resolve the string entity slug to a fully qualified class name or null if missing.
     *
     * @return class-string<BaseActiveRecord>|null
     */
    private function resolveModelClass(string $entity): ?string
    {
        $key = strtolower($entity);

        return $this->entityMapping[$key] ?? null;
    }
}