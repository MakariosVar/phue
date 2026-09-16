<?php

declare(strict_types=1);

namespace Phue\Modules\Phii\Generator;

use Phue\Modules\Phii\Generator\FileMutation;

/**
 * Generates CRUD and resource controllers following project console command conventions.
 *
 * @package Phue\Modules\Phii\Generator
 */
final class ControllerGenerator
{
    /**
     * Generate a controller file mutation with routing attributes.
     *
     * @param string             $moduleName Module slug.
     * @param string             $modelName  Model name.
     * @param string             $basePath   Target directory path.
     * @param array<int, string> $actions    List of CRUD actions to generate.
     *
     * @return array<int, FileMutation> List of file mutations.
     */
    public function generate(
        string $moduleName,
        string $modelName,
        string $basePath,
        array $actions = ['index', 'store', 'show', 'update', 'delete']
    ): array {
        $templateFile = __DIR__ . '/templates/controller.php';
        $template = file_get_contents($templateFile);
        if ($template === false) {
            throw new \RuntimeException("Failed to load template: {$templateFile}");
        }

        $controllerName = "{$modelName}Controller";
        $namespace = "Phue\\{$moduleName}\\Controller";
        $routePrefix = "/{$moduleName}/" . strtolower($modelName);

        $actionsCode = $this->buildActions($routePrefix, $actions);

        $output = str_replace(
            ['{{ NAMESPACE }}', '{{ MODEL_NAME }}', '{{ ACTIONS }}'],
            [$namespace, $modelName, $actionsCode],
            $template
        );

        $filePath = rtrim($basePath, '/') . "/{$controllerName}.php";

        return [new FileMutation($filePath, $output)];
    }

    /**
     * Builds action methods matching project conventions from MakeControllerCommand.
     *
     * @param string             $routePrefix
     * @param array<int, string> $actions
     *
     * @return string
     */
    private function buildActions(string $routePrefix, array $actions): string
    {
        $methods = [];

        foreach ($actions as $action) {
            $routePath = match ($action) {
                'index' => $routePrefix,
                'store' => $routePrefix,
                'show' => "{$routePrefix}/{id}",
                'update' => "{$routePrefix}/{id}",
                'delete' => "{$routePrefix}/{id}",
                default => "{$routePrefix}/{$action}",
            };

            $httpMethod = match ($action) {
                'store' => 'POST',
                'show' => 'GET',
                'update' => 'PUT',
                'delete' => 'DELETE',
                default => 'GET',
            };

            $routeName = "{$routePrefix}.{$action}";

            $methods[] = sprintf(
                "    #[Route('%s', methods: ['%s'], name: '%s')]\n    public function %s(Request \$request, mixed \$id = null): Response\n    {\n        return Response::json(['status' => 'ok']);\n    }",
                $routePath,
                $httpMethod,
                $routeName,
                $action
            );
        }

        return implode("\n\n", $methods);
    }
}