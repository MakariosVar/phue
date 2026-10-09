<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Endpoint;

use Phue\Core\Database\ActiveRecord\ActiveQuery;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Validation\RuleEngine;
use function Phue\Core\t;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Schii\Audit\SchiiAudit;
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
     * @param AuthManager|null                       $authManager    Authentication manager for resolving current user.
     * @param array<string, class-string<BaseActiveRecord>> $entityMapping  Explicit map of entity slugs to ActiveRecord classes.
     * @param string                                 $entityNamespace Base PHP namespace for dynamic entity resolutions.
     */
    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private array $entityMapping = [],
        private string $entityNamespace = "App\\Models",
        private readonly ?AuthManager $authManager = null,
    ) {}

    /**
     * Helper to resolve the authenticated user ID.
     */
    private function getAuthUserId(Request $request): ?string
    {
        if ($this->authManager !== null && method_exists($this->authManager, 'authenticate')) {
            try {
                $user = $this->authManager->authenticate($request);
                if ($user && method_exists($user, 'getId')) {
                    return (string) $user->getId();
                }
            } catch (Throwable) {
                // Ignore auth failures for optional or unauthenticated contexts
            }
        }
        return null;
    }

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
            return Response::json(["error" => t('Resource not found')], 404);
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
            return Response::json(["error" => t('Resource not found')], 404);
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
            return Response::json(["error" => t('Resource not found')], 404);
        }

        $model = $modelClass::find($id);
        if (!$model) {
            return Response::json(["error" => t('Resource not found')], 404);
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
            return Response::json(["error" => t('Resource not found')], 404);
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
                    "error" => t('Validation failed'),
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
            // save() returns false for two unrelated reasons, and conflating
            // them told the client its input was wrong when the database was.
            // Validation failures carry getErrors(); a write that failed for
            // any other reason carries none, and that is a server fault.
            $modelErrors = $model->getErrors();

            if ($modelErrors === []) {
                return Response::json(["error" => t('Failed to save record')], 500);
            }

            return Response::json(
                [
                    "error" => t('Validation failed'),
                    "errors" => $modelErrors,
                ],
                422,
            );
        }

        // Audit Log Hook for store (POST)
        SchiiAudit::log(
            (string) $entity,
            'POST',
            $this->getAuthUserId($request),
            ['id' => method_exists($model, 'getId') ? $model->getId() : null, 'data' => $inputData]
        );

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
            return Response::json(["error" => t('Resource not found')], 404);
        }

        $model = $modelClass::find($id);
        if (!$model) {
            return Response::json(["error" => t('Resource not found')], 404);
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
                    "error" => t('Validation failed'),
                    "errors" => $errors,
                ],
                422,
            );
        }

        $inputData = $this->extractInputData($rawBody, $modelClass);

        $model->fill($inputData);

        if (!$model->save()) {
            // save() returns false for two unrelated reasons, and conflating
            // them told the client its input was wrong when the database was.
            // Validation failures carry getErrors(); a write that failed for
            // any other reason carries none, and that is a server fault.
            $modelErrors = $model->getErrors();

            if ($modelErrors === []) {
                return Response::json(["error" => t('Failed to save record')], 500);
            }

            return Response::json(
                [
                    "error" => t('Validation failed'),
                    "errors" => $modelErrors,
                ],
                422,
            );
        }

        // Audit Log Hook for update (PUT)
        SchiiAudit::log(
            (string) $entity,
            'PUT',
            $this->getAuthUserId($request),
            ['id' => $id, 'data' => $inputData]
        );

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
            return Response::json(["error" => t('Resource not found')], 404);
        }

        $model = $modelClass::find($id);
        if (!$model) {
            return Response::json(["error" => t('Resource not found')], 404);
        }

        if (!$model->delete()) {
            return Response::json(
                ["error" => t('Failed to delete resource')],
                500,
            );
        }

        // Audit Log Hook for delete (DELETE)
        SchiiAudit::log(
            (string) $entity,
            'DELETE',
            $this->getAuthUserId($request),
            ['id' => $id]
        );

        return Response::json(["message" => t('Resource deleted successfully')]);
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
            return Response::json(["error" => t('Resource not found')], 404);
        }

        /** @var array<string, mixed> $rawBody */
        $rawBody = method_exists($request, "getParsedBody")
            ? ($request->getParsedBody() ?? [])
            : $request->input();

        $action = $rawBody["action"] ?? null;
        $ids = $rawBody["ids"] ?? [];
        $data = $rawBody["data"] ?? [];

        if (!in_array($action, ["update", "delete"], true) || !is_array($ids) || empty($ids)) {
            return Response::json(["error" => t('Invalid bulk action or empty ids')], 422);
        }

        $ids = array_values(array_unique(array_filter($ids, static fn($id) => !is_array($id))));
        if (empty($ids)) {
            return Response::json(["error" => t('Invalid ids provided')], 422);
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
                        // Audit log per deleted item in bulk
                        SchiiAudit::log(
                            (string) $entity,
                            'BULK_DELETE',
                            $this->getAuthUserId($request),
                            ['id' => $id]
                        );
                    } else {
                        $failedCount++;
                        $results[] = ["id" => $id, "status" => "failed", "error" => t('Deletion failed')];
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
                        // Audit log per updated item in bulk
                        SchiiAudit::log(
                            (string) $entity,
                            'BULK_UPDATE',
                            $this->getAuthUserId($request),
                            ['id' => $id, 'data' => $inputData]
                        );
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
            return Response::json(["error" => t('Resource not found')], 404);
        }

        $schema = $this->schemaRegistry->get($modelClass);

        // Build whitelist based on fields where column === true
        $exportableFields = array_keys(
            array_filter($schema->fields, static fn($field) => $field->column === true)
        );
        $allowedKeys = array_flip($exportableFields);

        $format = strtolower((string) ($request->input("format") ?? $request->getQuery("format") ?? "csv"));
        $query = $this->buildQuery($request, $modelClass);
        $items = $query->get();

        // Transform and filter each record against the allowed column names
        $rows = array_map(
            static fn(BaseActiveRecord $item) => array_intersect_key($item->toArray(), $allowedKeys),
            $items
        );

        if ($format === "json") {
            return Response::json($rows);
        }

        // CSV Streaming via php://temp
        $stream = fopen("php://temp", "w+");
        if ($stream === false) {
            return Response::json(["error" => t('Failed to open temporary stream')], 500);
        }

        if (!empty($rows)) {
            // Extract headers directly from the first filtered row
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
            return Response::json(["error" => t('Resource not found')], 404);
        }

        $format = strtolower((string) ($request->input("format") ?? $request->getQuery("format") ?? "json"));
        $rawBody = method_exists($request, "getParsedBody")
            ? ($request->getParsedBody() ?? [])
            : $request->input();

        $dataInput = $rawBody["data"] ?? null;
        $matchBy = $rawBody["matchBy"] ?? null;

        if ($dataInput === null) {
            return Response::json(["error" => t('Data payload is required')], 400);
        }

        $rows = [];
        if ($format === "csv") {
            if (!is_string($dataInput)) {
                return Response::json(["error" => t('CSV data must be a string')], 400);
            }
            $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $dataInput)));
            if (empty($lines)) {
                return Response::json(["error" => t('Empty CSV payload')], 400);
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
                $errors[] = ["row" => $index, "error" => t('Row must be an array')];
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

        // 2. Dynamic Filtering across filterable fields (support flat & filter[field_name] syntax)
        $filterInputs = $request->input("filter") ?? $request->getQuery("filter");
        if (!is_array($filterInputs)) {
            $filterInputs = [];
        }

        foreach ($schema->fields as $field) {
            if ($field->filterable) {
                $value = $filterInputs[$field->name] 
                    ?? ($request->input($field->name) ?? $request->getQuery($field->name));

                if ($value !== "" && $value !== null) {
                    $query->where($field->name, "=", $value);
                }
            }
        }

        // 3. Dynamic Sorting (support separate sort/direction & combined sort=field:asc/desc formats)
        /** @var string|null $sortParam */
        $sortParam = $request->input("sort") ?? $request->getQuery("sort");
        $sortField = null;
        $direction = null;

        if ($sortParam) {
            if (str_contains($sortParam, ':')) {
                [$sortField, $sortDir] = explode(':', $sortParam, 2);
                $direction = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';
            } else {
                $sortField = $sortParam;
                $direction = strtoupper(
                    (string) ($request->input("direction") ??
                        ($request->getQuery("direction") ?? "ASC")),
                ) === "DESC" ? "DESC" : "ASC";
            }
        }

        if (
            $sortField &&
            isset($schema->fields[$sortField]) &&
            $schema->fields[$sortField]->sortable
        ) {
            $query->orderBy($sortField, $direction ?? 'ASC');
        }

        return $query;
    }

    /**
     * Extracts allowed input data, whitelisting schema fields, respecting
     * writable/readonly flags, and protecting primary keys.
     *
     * @param array<string, mixed>            $data       Raw incoming request data.
     * @param class-string<BaseActiveRecord> $modelClass Fully qualified model class name.
     *
     * @return array<string, mixed> Whitelisted data array.
     */
    private function extractInputData(array $data, string $modelClass): array
    {
        $schema = $this->schemaRegistry->get($modelClass);

        // Filter out fields that are explicitly not writable or marked as readonly
        $allowedFields = array_filter(
            array_keys($schema->fields),
            function (string $fieldName) use ($schema): bool {
                $field = $schema->fields[$fieldName];

                return $field->writable && !$field->readonly;
            }
        );

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
     * Rule MEANING lives in \Phue\Core\Validation\RuleEngine; this method only
     * decides which value is tested against which rules, and with which
     * create-vs-update semantics. The vocabulary is deliberately not repeated
     * here — an earlier version carried its own copy of it, and the copy and
     * the engine were free to drift.
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
                $errors[$field] = t("Unknown field '{field}' is not allowed", ['field' => $field]);
            }
        }

        // 2. Validate Known Fields & Schema Rules
        foreach ($schema->fields as $fieldName => $fieldSchema) {
            $value = $data[$fieldName] ?? null;

            // A value that is absent has no type to validate against, so
            // presence is settled first and the rest of the rules are skipped.
            // `required` reaches the same conclusion as the field's own flag:
            // a partial update must not be asked for every column.
            $required = $fieldSchema->required || RuleEngine::isRequired($fieldSchema->rules);

            if (!$isUpdate && $required && ($value === null || $value === "")) {
                $errors[$fieldName] = "Field '{$fieldName}' is required";
                continue;
            }

            if ($value === null || $value === "") {
                continue;
            }

            $rules = $fieldSchema->rules;

            // A numeric column holds a number or it is corrupt, whether or not
            // the author restated it. The type is normalised by the schema
            // registry, so 'number' and 'numeric' have already become 'float'
            // by this point and must not be listed again.
            if (RuleEngine::isNumericType($fieldSchema->type)) {
                $rules[] = RuleEngine::RULE_NUMERIC;
            }

            $failure = RuleEngine::check($rules, $value, $fieldSchema->type, $fieldName);

            if ($failure !== null) {
                $errors[$fieldName] = $failure;
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