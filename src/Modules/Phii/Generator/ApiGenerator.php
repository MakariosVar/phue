<?php

declare(strict_types=1);

namespace Phue\Modules\Phii\Generator;

use Phue\Modules\Phii\Generator\FileMutation;
use Phue\Modules\Schii\Generator\PhiiExportSchema;

/**
 * Generates zero-controller REST API controllers and router binding code mutations.
 *
 * @package Phue\Modules\Phii\Generator
 */
final class ApiGenerator
{
    /**
     * Generate API controller file mutation and unique router binding mutations for a schema.
     *
     * @param PhiiExportSchema $schema   Target schema export.
     * @param string           $basePath Output directory path.
     *
     * @return array<int, FileMutation> List of file mutations.
     */
    public function generate(PhiiExportSchema $schema, string $basePath = ''): array
    {
        $templateFile = __DIR__ . '/templates/api-controller.php';
        $template = file_get_contents($templateFile);
        if ($template === false) {
            throw new \RuntimeException("Failed to load template: {$templateFile}");
        }

        $modelName = $schema->entity;
        $controllerName = "{$modelName}ApiController";
        $namespace = 'Phue\\Modules\\Schii\\Api';

        $output = str_replace(
            ['{{ NAMESPACE }}', '{{ MODEL_NAME }}', '{{ TABLE_NAME }}', '{{ ACTIONS }}'],
            [$namespace, $modelName, $schema->table, $this->buildActions($modelName)],
            $template
        );

        $filePath = rtrim($basePath, '/') . "/{$controllerName}.php";

        $routeSlug = strtolower($schema->entity);
        $routeSnippet = $this->generateRoutes($schema);
        $routePath = rtrim($basePath, '/') . "/routes-{$routeSlug}-snippet.php";

        return [
            new FileMutation($filePath, $output),
            new FileMutation($routePath, $routeSnippet),
        ];
    }

    /**
     * Generate full CRUD router registration code snippet for the API schema.
     *
     * @param PhiiExportSchema $schema
     *
     * @return string
     */
    public function generateRoutes(PhiiExportSchema $schema): string
    {
        $routeSlug = strtolower($schema->entity);
        $entity = $schema->entity;

        return sprintf(
            "\$router->addRoute('GET', '/api/v1/%s', [\\Phue\\Schii\\Api\\%sApiController::class, 'index']);\n" .
            "\$router->addRoute('GET', '/api/v1/%s/{id}', [\\Phue\\Schii\\Api\\%sApiController::class, 'show']);\n" .
            "\$router->addRoute('POST', '/api/v1/%s', [\\Phue\\Schii\\Api\\%sApiController::class, 'store']);\n" .
            "\$router->addRoute('PUT', '/api/v1/%s/{id}', [\\Phue\\Schii\\Api\\%sApiController::class, 'update']);\n" .
            "\$router->addRoute('DELETE', '/api/v1/%s/{id}', [\\Phue\\Schii\\Api\\%sApiController::class, 'delete']);",
            $routeSlug, $entity,
            $routeSlug, $entity,
            $routeSlug, $entity,
            $routeSlug, $entity,
            $routeSlug, $entity
        );
    }

    /**
     * Build standard CRUD action methods for the API controller.
     *
     * @param string $modelName
     *
     * @return string
     */
    private function buildActions(string $modelName): string
    {
        return <<<'CODE'
    public function index(): Response
    {
        // TODO: Implement index action
    }

    public function show($id): Response
    {
        // TODO: Implement show action
    }

    public function store(\Phue\Core\Request $request): Response
    {
        // TODO: Implement store action
    }

    public function update(\Phue\Core\Request $request, $id): Response
    {
        // TODO: Implement update action
    }

    public function delete($id): Response
    {
        // TODO: Implement delete action
    }
CODE;
    }
}
