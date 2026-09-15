<?php

declare(strict_types=1);

namespace Phue\Rest\Endpoint;

use Phue\Schii\Endpoint\ModelEndpoint;
use Phue\Schii\Schema\SchemaRegistry;
use Phue\Auth\AuthManager;
use Phue\Auth\RbacManager;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Http\Exceptions\HttpException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Class RestModelEndpoint
 *
 * Wraps the core ModelEndpoint to enforce resource-level policies, restricted operation 
 * filters, field-level redaction, and custom domain action executions with fine-grained access control.
 *
 * @package Phue\Rest\Endpoint
 */
class RestModelEndpoint
{
    /**
     * RestModelEndpoint constructor.
     *
     * @param ModelEndpoint $inner Underlying ModelEndpoint framework instance
     * @param SchemaRegistry $schemaRegistry Schema registry instance
     * @param AuthManager $authManager Auth manager used for token resolution and checks
     * @param RbacManager $rbacManager RBAC manager for role/permission validations
     * @param string $entityClass Fully qualified model class name mapped to this endpoint
     * @param array<int, string> $hidden List of sensitive field names to redact
     * @param bool $public Whether anonymous access is permitted
     * @param array<int, string> $operations Whitelist of permitted CRUD operations
     */
    public function __construct(
        private readonly ModelEndpoint $inner,
        private readonly SchemaRegistry $schemaRegistry,
        private readonly AuthManager $authManager,
        private readonly RbacManager $rbacManager,
        private readonly string $entityClass,
        private readonly array $hidden = [],
        private readonly bool $public = false,
        private readonly array $operations = ['index', 'show', 'store', 'update', 'delete']
    ) {
    }

    /**
     * Verifies if the requested CRUD operation is permitted and checks authentication requirements.
     *
     * @param string $operation The operation name being executed
     * @return void
     * @throws HttpException If operation is not allowed or authentication fails
     */
    private function checkAccessAndOperation(string $operation): void
    {
        if (!in_array($operation, $this->operations, true)) {
            throw new HttpException("Operation [{$operation}] is not allowed for this resource.", 405);
        }

        if (!$this->public) {
            if (!$this->authManager->check()) {
                throw new HttpException('Unauthorized access.', 401);
            }
        }
    }

    /**
     * Verifies authentication requirements specifically for custom domain actions (auth gate only).
     *
     * @param bool $actionPublic Visibility override for the action
     * @return void
     * @throws HttpException If authentication fails
     */
    private function checkActionAccess(bool $actionPublic): void
    {
        if (!$actionPublic) {
            if (!$this->authManager->check()) {
                throw new HttpException('Unauthorized access.', 401);
            }
        }
    }

    /**
     * Removes hidden fields from a given data array.
     *
     * @param array<string, mixed> $data Associative array of model data
     * @return array<string, mixed> Redacted array
     */
    private function redactArray(array $data): array
    {
        foreach ($this->hidden as $field) {
            unset($data[$field]);
        }
        return $data;
    }

    /**
     * Redacts hidden fields recursively from decoded JSON response payloads or raw data.
     *
     * @param mixed $result Raw response data payload or objects
     * @return mixed Redacted result payload
     */
    private function redactResponse(mixed $result): mixed
    {
        if ($result instanceof Response) {
            $rawContent = $result->getContent();
            $decoded = json_decode($rawContent, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $redactedData = $this->redactResponseData($decoded);
                $result->setContent(json_encode($redactedData));
            }
            
            return $result;
        }

        return $this->redactResponseData($result);
    }

    /**
     * Helper to process arrays/paginated structures and apply proper redaction based on structure.
     *
     * @param mixed $data Decoded payload data
     * @return mixed Redacted data
     */
    private function redactResponseData(mixed $data): mixed
    {
        if (!is_array($data)) {
            if (is_object($data) && method_exists($data, 'toArray')) {
                return $this->redactArray($data->toArray());
            }
            return $data;
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $data['data'] = $this->redactResponseData($data['data']);
            return $data;
        }

        $isList = array_keys($data) === range(0, count($data) - 1);

        if ($isList) {
            return array_map(function ($item) {
                if (is_object($item) && method_exists($item, 'toArray')) {
                    return $this->redactArray($item->toArray());
                }
                if (is_array($item)) {
                    return $this->redactArray($item);
                }
                return $item;
            }, $data);
        }

        return $this->redactArray($data);
    }

    /**
     * Sanitizes incoming request body, ensuring hidden fields are not provided or modified.
     *
     * @param Request $request Incoming HTTP request instance
     * @return void
     * @throws HttpException If a restricted field is found in the input body
     */
    private function sanitizeInput(Request $request): void
    {
        $body = $request->getParsedBody();
        foreach ($this->hidden as $field) {
            if (isset($body[$field])) {
                throw new HttpException("Field [{$field}] is restricted and cannot be modified or provided.", 400);
            }
        }
    }

    /**
     * Handles listing entities with security and redaction controls.
     *
     * @param Request $request Incoming HTTP request
     * @param array<string, string>|string $entity Route parameters or entity name
     * @return Response HTTP Response instance
     */
    public function index(Request $request, array|string $entity = ''): Response
    {
        $this->checkAccessAndOperation('index');
        $resolvedEntity = is_array($entity) ? ($entity['entity'] ?? basename(str_replace('\\', '/', $this->entityClass))) : (empty($entity) ? basename(str_replace('\\', '/', $this->entityClass)) : $entity);
        $response = $this->inner->index($request, ['entity' => $resolvedEntity]);
        
        return $this->redactResponse($response);
    }

    /**
     * Handles displaying a single entity record.
     *
     * @param Request $request Incoming HTTP request
     * @param array<string, string>|string $entity Route parameters or entity name
     * @param mixed $id Record identifier
     * @return Response HTTP Response instance
     */
    public function show(Request $request, array|string $entity = '', mixed $id = null): Response
    {
        $this->checkAccessAndOperation('show');
        if (is_array($entity)) {
            $id = $entity['id'] ?? $id;
            $resolvedEntity = $entity['entity'] ?? basename(str_replace('\\', '/', $this->entityClass));
        } else {
            $resolvedEntity = empty($entity) ? basename(str_replace('\\', '/', $this->entityClass)) : $entity;
        }
        $response = $this->inner->show($request, ['entity' => $resolvedEntity, 'id' => $id]);
        
        return $this->redactResponse($response);
    }

    /**
     * Handles storing a new entity record after input sanitization.
     *
     * @param Request $request Incoming HTTP request containing input payload
     * @param array<string, string>|string $entity Route parameters or entity name
     * @return Response HTTP Response instance
     */
    public function store(Request $request, array|string $entity = ''): Response
    {
        $this->checkAccessAndOperation('store');
        $this->sanitizeInput($request);
        $resolvedEntity = is_array($entity) ? ($entity['entity'] ?? basename(str_replace('\\', '/', $this->entityClass))) : (empty($entity) ? basename(str_replace('\\', '/', $this->entityClass)) : $entity);
        $response = $this->inner->store($request, ['entity' => $resolvedEntity]);
        
        return $this->redactResponse($response);
    }

    /**
     * Handles updating an existing entity record after input sanitization.
     *
     * @param Request $request Incoming HTTP request containing modification payload
     * @param array<string, string>|string $entity Route parameters or entity name
     * @param mixed $id Record identifier
     * @return Response HTTP Response instance
     */
    public function update(Request $request, array|string $entity = '', mixed $id = null): Response
    {
        $this->checkAccessAndOperation('update');
        $this->sanitizeInput($request);
        if (is_array($entity)) {
            $id = $entity['id'] ?? $id;
            $resolvedEntity = $entity['entity'] ?? basename(str_replace('\\', '/', $this->entityClass));
        } else {
            $resolvedEntity = empty($entity) ? basename(str_replace('\\', '/', $this->entityClass)) : $entity;
        }
        $response = $this->inner->update($request, ['entity' => $resolvedEntity, 'id' => $id]);
        
        return $this->redactResponse($response);
    }

    /**
     * Handles deleting an entity record.
     *
     * @param Request $request Incoming HTTP request
     * @param array<string, string>|string $entity Route parameters or entity name
     * @param mixed $id Record identifier
     * @return Response HTTP Response instance
     */
    public function delete(Request $request, array|string $entity = '', mixed $id = null): Response
    {
        $this->checkAccessAndOperation('delete');
        if (is_array($entity)) {
            $id = $entity['id'] ?? $id;
            $resolvedEntity = $entity['entity'] ?? basename(str_replace('\\', '/', $this->entityClass));
        } else {
            $resolvedEntity = empty($entity) ? basename(str_replace('\\', '/', $this->entityClass)) : $entity;
        }
        return $this->inner->delete($request, ['entity' => $resolvedEntity, 'id' => $id]);
    }

    /**
     * Serves schema metadata for a specific entity while stripping hidden fields to prevent information leakage.
     *
     * @param Request $request Incoming HTTP request
     * @param array<string, string>|string $entity Route parameters or entity name
     * @return Response HTTP Response instance
     */
    public function schema(Request $request, array|string $entity = ''): Response
    {
        $this->checkAccessAndOperation('show');
        $resolvedEntity = is_array($entity) ? ($entity['entity'] ?? basename(str_replace('\\', '/', $this->entityClass))) : (empty($entity) ? basename(str_replace('\\', '/', $this->entityClass)) : $entity);
        $response = $this->inner->schema($request, ['entity' => $resolvedEntity]);
        
        $rawContent = $response->getContent();
        $data = json_decode($rawContent, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            if (isset($data['fields']) && is_array($data['fields'])) {
                // Fields is an indexed list of field definitions or names; filter correctly by value/name
                $data['fields'] = array_values(array_filter(
                    $data['fields'],
                    function ($field) {
                        $fieldName = is_array($field) ? ($field['name'] ?? null) : $field;
                        return !in_array($fieldName, $this->hidden, true);
                    }
                ));
            }
            $response->setContent(json_encode($data));
        }

        return $this->redactResponse($response);
    }

    /**
     * Lists available schemas securely.
     *
     * @param Request $request Incoming HTTP request
     * @return Response HTTP Response instance
     */
    public function listSchemas(Request $request): Response
    {
        $this->checkAccessAndOperation('index');
        return $this->inner->listSchemas($request);
    }

    /**
     * Executes custom model actions (collection or member level) with domain-pure argument binding and full redaction.
     *
     * @param Request $request Incoming HTTP request
     * @param array<string, mixed> $params Route parameters containing id or custom tokens
     * @param string $actionName Name of the method/action to invoke on the model
     * @param bool $actionPublic Method-level public visibility override
     * @return Response HTTP Response instance
     * @throws HttpException If method execution or access fails
     */
    public function action(Request $request, array $params, string $actionName, bool $actionPublic): Response
    {
        $this->checkActionAccess($actionPublic);

        $reflectionMethod = new ReflectionMethod($this->entityClass, $actionName);
        $methodArgs = [];

        $isMember = false;
        foreach ($reflectionMethod->getParameters() as $parameter) {
            if ($parameter->getName() === 'id') {
                $isMember = true;
                break;
            }
        }

        if ($isMember) {
            $id = $params['id'] ?? null;
            if ($id === null) {
                throw new HttpException("Missing 'id' parameter for member action [{$actionName}].", 400);
            }
            
            $modelInstance = call_user_func([$this->entityClass, 'find'], $id);
            if (!$modelInstance) {
                throw new HttpException("Entity record not found.", 404);
            }

            foreach ($reflectionMethod->getParameters() as $parameter) {
                $paramName = $parameter->getName();
                if ($paramName === 'id') {
                    $methodArgs[] = $id;
                } elseif ($parameter->getType() && $parameter->getType()->getName() === 'array') {
                    $methodArgs[] = $params;
                } else {
                    $methodArgs[] = $request->getParsedBody()[$paramName] ?? ($params[$paramName] ?? null);
                }
            }

            $result = $reflectionMethod->invokeArgs($modelInstance, $methodArgs);
        } else {
            foreach ($reflectionMethod->getParameters() as $parameter) {
                $paramName = $parameter->getName();
                if ($parameter->getType() && $parameter->getType()->getName() === 'array') {
                    $methodArgs[] = $params;
                } else {
                    $methodArgs[] = $request->getParsedBody()[$paramName] ?? ($params[$paramName] ?? null);
                }
            }

            $result = $reflectionMethod->isStatic() 
                ? $reflectionMethod->invokeArgs(null, $methodArgs) 
                : $reflectionMethod->invokeArgs(new $this->entityClass(), $methodArgs);
        }

        $redactedResult = $this->redactResponseData($result);

        if ($redactedResult instanceof Response) {
            return $redactedResult;
        }

        $response = new Response();
        $response->setContent(json_encode($redactedResult));
        return $response;
    }
}