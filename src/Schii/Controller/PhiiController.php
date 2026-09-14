<?php

declare(strict_types=1);

namespace Phue\Schii\Controller;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\UI\ComponentRenderer;
use Phue\Generator\ControllerGenerator;
use Phue\Generator\CrudGenerator;
use Phue\Generator\MigrationGenerator;
use Phue\Generator\ModelGenerator;
use Phue\Generator\ModuleGenerator;
use Phue\Schii\Config\SchiiConfig;
use Phue\Schii\Schema\SchemaRegistry;

final class PhiiController
{
    private ComponentRenderer $renderer;
    private SchemaRegistry $schemaRegistry;
    private SchiiConfig $config;
    private Application $app;
    private ModelGenerator $modelGenerator;
    private ControllerGenerator $controllerGenerator;
    private MigrationGenerator $migrationGenerator;
    private CrudGenerator $crudGenerator;
    private ModuleGenerator $moduleGenerator;

    public function __construct(
        ComponentRenderer $renderer,
        SchemaRegistry $schemaRegistry,
        SchiiConfig $config,
        Application $app,
        ?ModelGenerator $modelGenerator = null,
        ?ControllerGenerator $controllerGenerator = null,
        ?MigrationGenerator $migrationGenerator = null,
        ?CrudGenerator $crudGenerator = null,
        ?ModuleGenerator $moduleGenerator = null
    ) {
        $this->renderer = $renderer;
        $this->schemaRegistry = $schemaRegistry;
        $this->config = $config;
        $this->app = $app;
        $this->modelGenerator = $modelGenerator ?? new ModelGenerator();
        $this->controllerGenerator = $controllerGenerator ?? new ControllerGenerator();
        $this->migrationGenerator = $migrationGenerator ?? new MigrationGenerator();
        $this->crudGenerator = $crudGenerator ?? new CrudGenerator();
        $this->moduleGenerator = $moduleGenerator ?? new ModuleGenerator();
    }

    public function index(): Response
    {
        $state = [
            'apiPrefix' => $this->config->apiPrefix ?? '/api/schii',
            'phiiNamespace' => $this->config->phiiNamespace ?? 'Phue\\Phii',
            'module' => 'admin',
        ];

        return $this->renderer->render('Phii', ['state' => $state]);
    }

    public function generate(Request $request): Response
    {
        try {
            $body = (array) $request->getParsedBody();

            $entity = $body['entity'] ?? null;
            $generator = $body['generator'] ?? null;
            $module = $body['module'] ?? 'admin';
            $options = $body['options'] ?? [];

            if ($generator !== 'module' && $entity === null) {
                return new Response(
                    json_encode(['error' => 'Missing entity for generator.']),
                    400,
                    ['Content-Type' => 'application/json']
                );
            }

            $basePath = $this->config->exportDir ?? $this->app->getBasePath();
            $paths = [];

            switch ($generator) {
                case 'model':
                    $schema = $this->schemaRegistry->toPhiiExportSchema($entity, $module);
                    $type = $options['type'] ?? 'active-record';
                    $paths[] = $this->modelGenerator->generateFromSchema($schema, $type, $basePath);
                    break;

                case 'controller':
                    $modelClass = $this->resolveModelClass($entity);
                    $modelName = (new \ReflectionClass($modelClass))->getShortName();
                    $actions = $options['actions'] ?? ['index', 'store', 'show', 'update', 'delete'];
                    $paths[] = $this->controllerGenerator->generate($module, $modelName, $basePath, $actions);
                    break;

                case 'migration':
                    $schema = $this->schemaRegistry->toPhiiExportSchema($entity, $module);
                    $driver = $options['driver'] ?? 'sqlite';
                    $paths[] = $this->migrationGenerator->generateFromSchema($schema, $basePath, $driver);
                    break;

                case 'crud':
                    $schema = $this->schemaRegistry->toPhiiExportSchema($entity, $module);
                    $results = $this->crudGenerator->generate($schema, $module, $basePath);
                    $paths = array_values($results);
                    break;

                case 'module':
                    $moduleName = $entity ?? ($options['name'] ?? 'NewModule');
                    $modOptions = [
                        'auth' => (bool) ($options['auth'] ?? false),
                        'api' => (bool) ($options['api'] ?? false),
                    ];
                    $paths = $this->moduleGenerator->generate($moduleName, $basePath, $modOptions);
                    break;

                default:
                    return new Response(
                        json_encode(['error' => "Unsupported generator type: '{$generator}'."]),
                        400,
                        ['Content-Type' => 'application/json']
                    );
            }

            return new Response(
                json_encode(['paths' => $paths], JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json']
            );
        } catch (\Throwable $e) {
            $code = ($e instanceof \InvalidArgumentException) ? 400 : 500;
            return new Response(
                json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR),
                $code,
                ['Content-Type' => 'application/json']
            );
        }
    }

    private function resolveModelClass(string $input): string
    {
        if (class_exists($input)) {
            return $input;
        }

        $studlyName = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $input)));
        $fqcn = "App\\Models\\{$studlyName}";

        if (class_exists($fqcn)) {
            return $fqcn;
        }

        throw new \InvalidArgumentException("Could not resolve model class for '{$input}' (tried {$fqcn}).");
    }
}