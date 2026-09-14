<?php

declare(strict_types=1);

namespace Phue\Generator;

use Phue\Schii\Generator\PhiiExportSchema;

/**
 * Generates zero-controller REST API controllers and router binding code.
 *
 * @package Phue\Generator
 */
final class ApiGenerator
{
    /**
     * Generate API controller and route code for a schema.
     *
     * @param PhiiExportSchema $schema   Target schema export.
     * @param string           $basePath Output directory path.
     *
     * @return array<string, string> Generated API controller and route snippets.
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
        $namespace = 'Phue\\Schii\\Api';

        $output = str_replace(
            ['{{ NAMESPACE }}', '{{ MODEL_NAME }}', '{{ TABLE_NAME }}', '{{ ACTIONS }}'],
            [$namespace, $modelName, $schema->table, $this->buildActions($modelName)],
            $template
        );

        $filePath = rtrim($basePath, '/') . "/{$controllerName}.php";
        if ($basePath !== '' && !is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        file_put_contents($filePath, $output);

        return [
            'api_controller' => $filePath,
            'routes' => $this->generateRoutes($schema),
        ];
    }

    /**
     * Generate router registration code for the API schema.
     *
     * @param PhiiExportSchema $schema
     *
     * @return string
     */
    public function generateRoutes(PhiiExportSchema $schema): string
    {
        $routeSlug = strtolower($schema->entity);
        return sprintf(
            "\$router->addRoute('GET', '/api/v1/%s', [\\Phue\\Schii\\Api\\%sApiController::class, 'index']);",
            $routeSlug,
            $schema->entity
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