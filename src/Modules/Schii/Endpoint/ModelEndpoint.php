<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Endpoint;

use Phue\Core\Database\ActiveRecord\ActiveQuery;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Throwable;

/**
 * Class ModelEndpoint
 *
 * Handles automatic REST API endpoints for Schii-annotated models.
 * Dynamically resolves entity slugs to ActiveRecord class representations
 * based on namespace conventions or explicit mappings.
 *
 * @package Phue\Modules\Schii\Endpoint
 */
final class ModelEndpoint
{
    /**
     * ModelEndpoint constructor.
     *
     * @param SchemaRegistry                         $schemaRegistry Registry parsing model reflection schema definitions.
     * @param array<string, class-string<BaseActiveRecord>> $entityMapping  Explicit map of entity slugs to ActiveRecord classes.
     * @param string                                 $entityNamespace Base PHP namespace for dynamic entity resolutions.
     */
    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private array $entityMapping = [],
        private string $entityNamespace = "App\\Models",
    ) {}

    /**
     * Register an explicit entity slug mapping with its corresponding ActiveRecord class.
     *
     * @param string                         $entity     The URL slug/identifier for the target entity.
     * @param class-string<BaseActiveRecord> $modelClass Fully qualified class name of the target ActiveRecord.
     *
     * @return void
     */
    public function registerEntity(string $entity, string $modelClass): void
    {
        $this->entityMapping[strtolower($entity)] = $modelClass;
    }

    /**
     * List all available entities dynamically for server-authoritative frontend discovery.
     *
     * @route GET /api/schii/schema
     *
     * @param Request      $request The incoming HTTP request instance.
     * @param array|string $params  Route parameters (if any).
     *
     * @return Response JSON response containing the list of available entity slugs.
     */
    public function listSchemas(Request $request, array|string $params = []): Response
    {
        $entities = array_keys($this->entityMapping);

        if (method_exists($this->schemaRegistry, 'discoverEntities')) {
            $discovered = $this->schemaRegistry->discoverEntities($this->entityNamespace);
            $entities = array_unique(array_merge($entities, $discovered));
        }

        return Response::json([
            "entities" => array_values($entities),
        ]);
    }

    /**
     * Retrieve and return the JSON Schema representation for a target entity.
     *
     * @route GET /api/schii/schema/{entity}
     *
     * @param Request      $request The incoming HTTP request instance.
     * @param array|string $params  Route parameters containing the target entity slug.
     *
     * @return Response JSON response containing the schema dictionary or a 404 error.
     */
    public function schema(Request $request, array|string $params): Response
    {
        /** @var string $entity */
        $entity = is_array($params)
            ? $params["entity"] ?? (reset($params) ?: "")
            : $params;
        $modelClass = $this->resolveModelClass((string) $entity);

        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        $schema = $this->schemaRegistry->get($modelClass);

        return Response::json($schema->toArray());
    }

    /**
     * List entity records with support for searching, filtering, sorting, and pagination.
     *
     * @route GET /api/schii/{entity}
     *
     * @param Request      $request The incoming HTTP request instance.
     * @param array|string $params  Route parameters containing the target entity slug.
     *
     * @return Response JSON response containing items and pagination meta data, or a 404 error.
     */
    public function index(Request $request, array|string $params): Response
    {
        /** @var string $entity */
        $entity = is_array($params)
            ? $params["entity"] ?? (reset($params) ?: "")
            : $params;
        $modelClass = $this->resolveModelClass((string) $entity);

        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        // Delegate query construction to buildQuery (Single Source of Truth)
        $query = $this->buildQuery($request, $modelClass);

        // 4. Pagination
        $page = max(
            1,
            (int) ($request->input("page") ??
                ($request->getQuery("page") ?? 1)),
        );
        $limit = max(
            1,
            min(
                100,
                (int) ($request->input("limit") ??
                    ($request->getQuery("limit") ?? 25)),
            ),
        );
        $total = (int) (clone $query)->count();

        $items = $query
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        return Response::json([
            "data" => array_map(
                static fn(BaseActiveRecord $item) => $item->toArray(),
                $items,
            ),
            "meta" => [
                "total" => $total,
                "page" => $page,
                "limit" => $limit,
                "last_page" => (int) ceil($total / $limit),
            ],
        ]);
    }

    /**
     * Retrieve a specific entity record by its primary identifier.
     *
     * @route GET /api/schii/{entity}/{id}
     *
     * @param Request $request The incoming HTTP request instance.
     * @param array   $params  Route parameters containing entity slug and record ID.
     *
     * @return Response JSON response containing the model payload or a 404 error.
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
     * Create and store a new entity record.
     *
     * @route POST /api/schii/{entity}
     *
     * @param Request      $request The incoming HTTP request instance.
     * @param array|string $params  Route parameters containing the target entity slug.
     *
     * @return Response JSON response containing created record payload (201 Created) or validation errors (422).
     */
    public function store(Request $request, array|string $params): Response
    {
        /** @var string $entity */
        $entity = is_array($params)
            ? $params["entity"] ?? (reset($params) ?: "")
            : $params;
        $modelClass = $this->resolveModelClass((string) $entity);

        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        /** @var array<string, mixed> $rawBody */
        $rawBody = method_exists($request, "getParsedBody")
            ? array_merge(
                $request->getQueryParams(),
                $request->getParsedBody() ?? [],
            )
            : array_merge($request->getQueryParams(), $request->input());

        $errors = $this->validateData($rawBody, $modelClass, isUpdate: false);
        if (!empty($errors)) {
            return Response::json(
                [
                    "error" => "Validation failed",
                    "errors" => $errors,
                ],
                422,
            );
        }

        $inputData = $this->extractInputData($rawBody, $modelClass);

        /** @var BaseActiveRecord $model */
        $model = new $modelClass();
        $model->fill($inputData);

        if (!$model->save()) {
            return Response::json(
                [
                    "error" => "Validation failed",
                    "errors" => $model->getErrors(),
                ],
                422,
            );
        }

        return Response::json(["data" => $model->toArray()], 201);
    }

    /**
     * Update an existing entity record.
     *
     * @route PUT /api/schii/{entity}/{id}
     *
     * @param Request $request The incoming HTTP request instance.
     * @param array   $params  Route parameters containing entity slug and record ID.
     *
     * @return Response JSON response with updated record payload (200 OK), 404 if missing, or 422 on validation error.
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

        /** @var array<string, mixed> $rawBody */
        $rawBody = method_exists($request, "getParsedBody")
            ? array_merge(
                $request->getQueryParams(),
                $request->getParsedBody() ?? [],
            )
            : array_merge($request->getQueryParams(), $request->input());

        $errors = $this->validateData($rawBody, $modelClass, isUpdate: true);
        if (!empty($errors)) {
            return Response::json(
                [
                    "error" => "Validation failed",
                    "errors" => $errors,
                ],
                422,
            );
        }

        $inputData = $this->extractInputData($rawBody, $modelClass);

        $model->fill($inputData);

        if (!$model->save()) {
            return Response::json(
                [
                    "error" => "Validation failed",
                    "errors" => $model->getErrors(),
                ],
                422,
            );
        }

        return Response::json(["data" => $model->toArray()]);
    }

    /**
     * Delete an existing entity record from database.
     *
     * @route DELETE /api/schii/{entity}/{id}
     *
     * @param Request $request The incoming HTTP request instance.
     * @param array   $params  Route parameters containing entity slug and record ID.
     *
     * @return Response JSON status response (200 OK), 404 if missing, or 500 on deletion failure.
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
            return Response::json(
                ["error" => "Failed to delete resource"],
                500,
            );
        }

        return Response::json(["message" => "Resource deleted successfully"]);
    }

    /**
     * Perform bulk operations (update or delete) on a set of entity records.
     *
     * @route POST /api/schii/{entity}/bulk
     *
     * @param Request      $request Incoming HTTP request instance.
     * @param array|string $params  Route parameters containing the entity slug.
     *
     * @return Response JSON response containing summary metrics and per-record results.
     */
    public function bulk(Request $request, array|string $params): Response
    {
        /** @var string $entity */
        $entity = is_array($params)
            ? $params["entity"] ?? (reset($params) ?: "")
            : $params;
        $modelClass = $this->resolveModelClass((string) $entity);

        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        /** @var array<string, mixed> $rawBody */
        $rawBody = method_exists($request, "getParsedBody")
            ? ($request->getParsedBody() ?? [])
            : $request->input();

        $action = $rawBody["action"] ?? null;
        $ids = $rawBody["ids"] ?? [];
        $data = $rawBody["data"] ?? [];

        if (!in_array($action, ["update", "delete"], true) || !is_array($ids) || empty($ids)) {
            return Response::json(["error" => "Invalid bulk action or empty ids"], 422);
        }

        $ids = array_values(array_unique(array_filter($ids, static fn($id) => !is_array($id))));
        if (empty($ids)) {
            return Response::json(["error" => "Invalid ids provided"], 422);
        }

        $updatedCount = 0;
        $deletedCount = 0;
        $notFoundCount = 0;
        $failedCount = 0;
        $results = [];

        foreach ($ids as $id) {
            try {
                $model = $modelClass::find($id);
                if (!$model) {
                    $notFoundCount++;
                    $results[] = ["id" => $id, "status" => "not_found"];
                    continue;
                }

                if ($action === "delete") {
                    if ($model->delete()) {
                        $deletedCount++;
                        $results[] = ["id" => $id, "status" => "deleted"];
                    } else {
                        $failedCount++;
                        $results[] = ["id" => $id, "status" => "failed", "error" => "Deletion failed"];
                    }
                } elseif ($action === "update") {
                    $errors = $this->validateData($data, $modelClass, isUpdate: true);
                    if (!empty($errors)) {
                        $failedCount++;
                        $results[] = ["id" => $id, "status" => "failed", "errors" => $errors];
                        continue;
                    }

                    $inputData = $this->extractInputData($data, $modelClass);
                    $model->fill($inputData);

                    if ($model->save()) {
                        $updatedCount++;
                        $results[] = ["id" => $id, "status" => "updated", "data" => $model->toArray()];
                    } else {
                        $failedCount++;
                        $results[] = ["id" => $id, "status" => "failed", "errors" => $model->getErrors()];
                    }
                }
            } catch (Throwable $e) {
                $failedCount++;
                $results[] = ["id" => $id, "status" => "failed", "error" => $e->getMessage()];
            }
        }

        return Response::json([
            "summary" => [
                "updated" => $updatedCount,
                "deleted" => $deletedCount,
                "notFound" => $notFoundCount,
                "failed" => $failedCount,
            ],
            "results" => $results,
        ], 200);
    }

    /**
     * Export entity records in CSV or JSON format.
     *
     * @route GET /api/schii/{entity}/export
     *
     * @param Request      $request Incoming HTTP request instance.
     * @param array|string $params  Route parameters containing the entity slug.
     *
     * @return Response Streaming CSV response or JSON payload.
     */
    public function export(Request $request, array|string $params): Response
    {
        /** @var string $entity */
        $entity = is_array($params)
            ? $params["entity"] ?? (reset($params) ?: "")
            : $params;
        $modelClass = $this->resolveModelClass((string) $entity);

        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        $format = strtolower((string) ($request->input("format") ?? $request->getQuery("format") ?? "csv"));
        $query = $this->buildQuery($request, $modelClass);
        $items = $query->get();

        if ($format === "json") {
            $rows = array_map(static fn(BaseActiveRecord $item) => $item->toArray(), $items);
            return Response::json($rows);
        }

        // CSV Streaming via php://temp
        $stream = fopen("php://temp", "w+");
        if ($stream === false) {
            return Response::json(["error" => "Failed to open temporary stream"], 500);
        }

        $rows = array_map(static fn(BaseActiveRecord $item) => $item->toArray(), $items);
        if (!empty($rows)) {
            fputcsv($stream, array_keys(reset($rows)), ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($stream, array_values($row), ',', '"', '\\');
            }
        }

        rewind($stream);
        $streamContent = stream_get_contents($stream);
        fclose($stream);

        return (new Response((string) $streamContent, 200))
            ->setHeader("Content-Type", "text/csv")
            ->setHeader("Content-Disposition", "attachment; filename=\"{$entity}.csv\"");
    }

    /**
     * Import entity records from CSV or JSON payload.
     *
     * @route POST /api/schii/{entity}/import
     *
     * @param Request      $request Incoming HTTP request instance.
     * @param array|string $params  Route parameters containing the entity slug.
     *
     * @return Response JSON summary response of imported, updated, and failed rows.
     */
    public function import(Request $request, array|string $params): Response
    {
        /** @var string $entity */
        $entity = is_array($params)
            ? $params["entity"] ?? (reset($params) ?: "")
            : $params;
        $modelClass = $this->resolveModelClass((string) $entity);

        if ($modelClass === null) {
            return Response::json(["error" => "Resource not found"], 404);
        }

        $format = strtolower((string) ($request->input("format") ?? $request->getQuery("format") ?? "json"));
        $rawBody = method_exists($request, "getParsedBody")
            ? ($request->getParsedBody() ?? [])
            : $request->input();

        $dataInput = $rawBody["data"] ?? null;
        $matchBy = $rawBody["matchBy"] ?? null;

        if ($dataInput === null) {
            return Response::json(["error" => "Data payload is required"], 400);
        }

        $rows = [];
        if ($format === "csv") {
            if (!is_string($dataInput)) {
                return Response::json(["error" => "CSV data must be a string"], 400);
            }
            $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $dataInput)));
            if (empty($lines)) {
                return Response::json(["error" => "Empty CSV payload"], 400);
            }
            $headers = str_getcsv(array_shift($lines), ',', '"', '\\');
            foreach ($lines as $line) {
                $values = str_getcsv($line, ',', '"', '\\');
                if (count($headers) === count($values)) {
                    $rows[] = array_combine($headers, $values);
                }
            }
        } elseif ($format === "json") {
            if (is_string($dataInput)) {
                $decoded = json_decode($dataInput, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $rows = $decoded;
                }
            } elseif (is_array($dataInput)) {
                $rows = $dataInput;
            }
            if (empty($rows)) {
                return Response::json(["error" => "Invalid or empty JSON data payload"], 400);
            }
        } else {
            return Response::json(["error" => "Unknown format"], 400);
        }

        $imported = 0;
        $updated = 0;
        $validated = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $failed++;
                $errors[] = ["row" => $index, "error" => "Row must be an array"];
                continue;
            }

            $validationErrors = $this->validateData($row, $modelClass, isUpdate: false);
            if (!empty($validationErrors)) {
                $failed++;
                $errors[] = ["row" => $index, "errors" => $validationErrors];
                continue;
            }
            $validated++;

            $inputData = $this->extractInputData($row, $modelClass);
            $modelInstance = null;

            if (!empty($matchBy) && isset($row[$matchBy])) {
                $modelInstance = $modelClass::query()->where($matchBy, "=", $row[$matchBy])->first();
            }

            if ($modelInstance) {
                $modelInstance->fill($inputData);
                if ($modelInstance->save()) {
                    $updated++;
                } else {
                    $failed++;
                    $errors[] = ["row" => $index, "errors" => $modelInstance->getErrors()];
                }
            } else {
                $modelInstance = new $modelClass();
                $modelInstance->fill($inputData);
                if ($modelInstance->save()) {
                    $imported++;
                } else {
                    $failed++;
                    $errors[] = ["row" => $index, "errors" => $modelInstance->getErrors()];
                }
            }
        }

        return Response::json([
            "imported" => $imported,
            "updated" => $updated,
            "validated" => $validated,
            "failed" => $failed,
            "errors" => $errors,
        ], 200);
    }

    /**
     * Builds and returns an ActiveQuery instance with global search, filtering, and sorting applied (without pagination).
     *
     * @param Request                      $request    Incoming HTTP request instance.
     * @param class-string<BaseActiveRecord> $modelClass Fully qualified model class name.
     *
     * @return ActiveQuery Configured query instance.
     */
    private function buildQuery(Request $request, string $modelClass): ActiveQuery
    {
        $schema = $this->schemaRegistry->get($modelClass);

        /** @var ActiveQuery $query */
        $query = $modelClass::query();

        // 1. Global Search with LIKE wildcard escaping
        /** @var string|null $searchQuery */
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
                $value =
                    $request->input($field->name) ??
                    $request->getQuery($field->name);
                if ($value !== "" && $value !== null) {
                    $query->where($field->name, "=", $value);
                }
            }
        }

        // 3. Dynamic Sorting
        /** @var string|null $sort */
        $sort = $request->input("sort") ?? $request->getQuery("sort");
        /** @var string $direction */
        $direction =
            strtoupper(
                (string) ($request->input("direction") ??
                    ($request->getQuery("direction") ?? "ASC")),
            ) === "DESC"
                ? "DESC"
                : "ASC";

        if (
            $sort &&
            isset($schema->fields[$sort]) &&
            $schema->fields[$sort]->sortable
        ) {
            $query->orderBy($sort, $direction);
        }

        return $query;
    }

    /**
     * Extracts allowed input data, whitelisting schema fields and protecting primary keys.
     *
     * @param array<string, mixed>            $data       Raw incoming request data.
     * @param class-string<BaseActiveRecord> $modelClass Fully qualified model class name.
     *
     * @return array<string, mixed> Whitelisted data array.
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
     * @param array<string, mixed>            $data       Incoming payload data.
     * @param class-string<BaseActiveRecord> $modelClass Target model class name.
     * @param bool                            $isUpdate   Flag denoting whether the operation is an update.
     *
     * @return array<string, string> Key-value dictionary of validation error messages.
     */
    private function validateData(
        array $data,
        string $modelClass,
        bool $isUpdate = false,
    ): array {
        $errors = [];
        $schema = $this->schemaRegistry->get($modelClass);
        $allowedFields = array_keys($schema->fields);

        /** @var BaseActiveRecord $tempModel */
        $tempModel = new $modelClass();
        $pk = $tempModel->getPrimaryKey();

        // 1. Strict Check for Unknown Fields
        foreach (array_keys($data) as $field) {
            if ($field === $pk) {
                continue;
            }
            if (!in_array($field, $allowedFields, true)) {
                $errors[$field] = "Unknown field '{$field}' is not allowed";
            }
        }

        // 2. Validate Known Fields & Schema Rules
        foreach ($schema->fields as $fieldName => $fieldSchema) {
            $value = $data[$fieldName] ?? null;

            if (
                !$isUpdate &&
                $fieldSchema->required &&
                ($value === null || $value === "")
            ) {
                $errors[$fieldName] = "Field '{$fieldName}' is required";
                continue;
            }

            if ($value === null || $value === "") {
                continue;
            }

            $rules = $fieldSchema->rules;
            if (
                $fieldSchema->type === "number" ||
                $fieldSchema->type === "numeric" ||
                $fieldSchema->type === "float"
            ) {
                $rules[] = "numeric";
            }

            foreach ($rules as $rule) {
                if ($rule === "numeric" && !is_numeric($value)) {
                    $errors[
                        $fieldName
                    ] = "Field '{$fieldName}' must be numeric";
                }
                if (
                    $rule === "integer" &&
                    filter_var($value, FILTER_VALIDATE_INT) === false
                ) {
                    $errors[
                        $fieldName
                    ] = "Field '{$fieldName}' must be an integer";
                }
                if (
                    $rule === "email" &&
                    filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ) {
                    $errors[
                        $fieldName
                    ] = "Field '{$fieldName}' must be a valid email";
                }
            }
        }

        return $errors;
    }

    /**
     * Resolve the string entity slug to a fully qualified ActiveRecord class name.
     *
     * Combines explicit mapping lookup with dynamic namespace resolution and singular/plural conventions.
     *
     * @param string $entity The entity slug or identifier from the URL.
     *
     * @return class-string<BaseActiveRecord>|null Resolved class name or null if non-existent.
     */
    private function resolveModelClass(string $entity): ?string
    {
        $key = strtolower(trim($entity));

        // 1. Check explicit mapping array
        if (isset($this->entityMapping[$key])) {
            return $this->entityMapping[$key];
        }

        // 2. Namespace resolution with singular/plural support
        $candidates = [$key];
        if (str_ends_with($key, "s") && strlen($key) > 1) {
            $candidates[] = substr($key, 0, -1);
        }

        $baseNamespace = rtrim($this->entityNamespace, "\\");

        foreach ($candidates as $candidate) {
            $studly = str_replace(
                " ",
                "",
                ucwords(str_replace(["-", "_"], " ", $candidate)),
            );
            $targetClass = "{$baseNamespace}\\{$studly}";

            if (class_exists($targetClass)) {
                return $targetClass;
            }
        }

        return null;
    }
}