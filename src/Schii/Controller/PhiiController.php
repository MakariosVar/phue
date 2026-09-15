<?php

declare(strict_types=1);

namespace Phue\Schii\Controller;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\UI\ComponentRenderer;
use Phue\Generator\ControllerGenerator;
use Phue\Generator\CrudGenerator;
use Phue\Generator\FileMutation;
use Phue\Generator\MigrationGenerator;
use Phue\Generator\ModelGenerator;
use Phue\Generator\ModuleGenerator;
use Phue\Phii\Generator\BackupManager;
use Phue\Schii\Config\SchiiConfig;
use Phue\Schii\Schema\SchemaRegistry;

/**
 * Class PhiiController
 *
 * Handles Phii panel UI rendering, mutation planning, previewing, and atomic code generation application.
 *
 * @package Phue\Schii\Controller
 */
final class PhiiController
{
    /** @var ComponentRenderer UI Component renderer instance. */
    private ComponentRenderer $renderer;

    /** @var SchemaRegistry Schema registry for entities. */
    private SchemaRegistry $schemaRegistry;

    /** @var SchiiConfig Schii configuration instance. */
    private SchiiConfig $config;

    /** @var Application Core application instance. */
    private Application $app;

    /** @var ModelGenerator Generator for model classes. */
    private ModelGenerator $modelGenerator;

    /** @var ControllerGenerator Generator for controller classes. */
    private ControllerGenerator $controllerGenerator;

    /** @var MigrationGenerator Generator for database migration files. */
    private MigrationGenerator $migrationGenerator;

    /** @var CrudGenerator Orchestrator generator for full CRUD scaffolds. */
    private CrudGenerator $crudGenerator;

    /** @var ModuleGenerator Generator for modular directory structures. */
    private ModuleGenerator $moduleGenerator;

    /** @var BackupManager Backup manager for creating backups. */
    private BackupManager $backupManager;

    /**
     * PhiiController constructor.
     *
     * @param ComponentRenderer        $renderer            UI renderer instance.
     * @param SchemaRegistry           $schemaRegistry      Schema registry.
     * @param SchiiConfig              $config              Schii configuration.
     * @param Application              $app                 Core application instance.
     * @param ModelGenerator|null      $modelGenerator      Optional model generator.
     * @param ControllerGenerator|null $controllerGenerator Optional controller generator.
     * @param MigrationGenerator|null  $migrationGenerator  Optional migration generator.
     * @param CrudGenerator|null       $crudGenerator       Optional CRUD generator.
     * @param ModuleGenerator|null     $moduleGenerator     Optional module generator.
     * @param BackupManager|null       $backupManager       Optional backup manager.
     */
    public function __construct(
        ComponentRenderer $renderer,
        SchemaRegistry $schemaRegistry,
        SchiiConfig $config,
        Application $app,
        ?ModelGenerator $modelGenerator = null,
        ?ControllerGenerator $controllerGenerator = null,
        ?MigrationGenerator $migrationGenerator = null,
        ?CrudGenerator $crudGenerator = null,
        ?ModuleGenerator $moduleGenerator = null,
        ?BackupManager $backupManager = null
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
        $this->backupManager = $backupManager ?? new BackupManager();
    }

    /**
     * Render the main Phii UI panel.
     *
     * @return Response HTTP response containing the rendered Phii view.
     */
    public function index(): Response
    {
        $state = [
            'apiPrefix' => $this->config->apiPrefix ?? '/api/schii',
            'phiiNamespace' => $this->config->phiiNamespace ?? 'Phue\\Phii',
            'module' => 'admin',
        ];

        return $this->renderer->render('Phii', ['state' => $state]);
    }

    /**
     * Execute direct file generation based on planned mutations.
     *
     * @param Request $request HTTP Request instance with parsed body data.
     * @return Response JSON response containing written file paths or error details.
     */
    public function generate(Request $request): Response
    {
        try {
            $body = (array) $request->getParsedBody();
            $generator = $body['generator'] ?? null;
            $basePath = $this->config->exportDir ?? $this->app->getBasePath();

            $mutations = $this->planMutations((string) $generator, $body, $basePath);
            $writtenPaths = [];

            foreach ($mutations as $mutation) {
                if ($mutation->write()) {
                    $writtenPaths[] = $mutation->path;
                }
            }

            return new Response(
                json_encode(['paths' => $writtenPaths], JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json']
            );
        } catch (\Throwable $e) {
            $code = 500;
            if ($e instanceof \DomainException) {
                $code = 403;
            } elseif ($e instanceof \InvalidArgumentException) {
                $code = 400;
            }
            return new Response(
                json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR),
                $code,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Preview generation plan without writing anything to disk.
     *
     * @param Request $request HTTP Request instance.
     * @return Response JSON response containing structural plan details.
     */
    public function preview(Request $request): Response
    {
        try {
            $body = (array) $request->getParsedBody();
            $generator = $body['generator'] ?? null;
            $entity = $body['entity'] ?? null;
            $basePath = $this->config->exportDir ?? $this->app->getBasePath();

            $mutations = $this->planMutations((string) $generator, $body, $basePath);
            $plan = [];

            foreach ($mutations as $mutation) {
                $exists = file_exists($mutation->path);
                $plan[] = [
                    'path' => $mutation->path,
                    'content' => $mutation->content,
                    'exists' => $exists,
                    'willOverwrite' => $exists,
                    'contentLength' => mb_strlen($mutation->content),
                    'summary' => 'Mutation for ' . basename($mutation->path),
                ];
            }

            return new Response(
                json_encode([
                    'plan' => $plan,
                    'generator' => $generator,
                    'entity' => $entity,
                ], JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json']
            );
        } catch (\Throwable $e) {
            $code = 500;
            if ($e instanceof \DomainException) {
                $code = 403;
            } elseif ($e instanceof \InvalidArgumentException) {
                $code = 400;
            }
            return new Response(
                json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR),
                $code,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Apply explicit file mutations from an execution plan with optional backup support.
     *
     * @param Request $request HTTP Request instance containing the plan and options.
     * @return Response JSON response detailing written counts, skips, conflicts, and backup paths.
     */
    public function apply(Request $request): Response
    {
        try {
            $body = (array) $request->getParsedBody();
            
            // Production environment security gate check
            $isProd = (method_exists($this->app, 'environment') && $this->app->environment() === 'production')
                || (method_exists($this->app, 'isProduction') && $this->app->isProduction());
            $allowProd = (bool) ($this->config->phii['allowInProduction'] ?? false);

            if ($isProd && !$allowProd) {
                return new Response(
                    json_encode(['error' => 'Phii is disabled in production'], JSON_THROW_ON_ERROR),
                    403,
                    ['Content-Type' => 'application/json']
                );
            }

            $planInput = $body['plan'] ?? [];
            $doBackup = (bool) ($body['backup'] ?? false);
            $basePath = $this->config->exportDir ?? $this->app->getBasePath();

            $written = 0;
            $skipped = 0;
            $conflicts = 0;
            $backupPath = null;

            if ($doBackup && !empty($planInput)) {
                $backupPath = $this->backupManager->createBackup($basePath);
            }

            foreach ($planInput as $item) {
                $path = $item['path'] ?? null;
                $content = $item['content'] ?? null;

                if (!$path) {
                    $skipped++;
                    continue;
                }

                if (file_exists($path)) {
                    $conflicts++;
                }

                $mutation = new FileMutation($path, (string) $content);
                if ($mutation->write()) {
                    $written++;
                } else {
                    $skipped++;
                }
            }

            return new Response(
                json_encode([
                    'written' => $written,
                    'skipped' => $skipped,
                    'conflicts' => $conflicts,
                    'backup' => $backupPath,
                ], JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json']
            );
        } catch (\Throwable $e) {
            return new Response(
                json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR),
                500,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Plan file mutations and enforce production safety gates.
     *
     * @param string               $generator Generator type identifier.
     * @param array<string, mixed> $body      Request body parameters.
     * @param string               $basePath  Base application path.
     * @return array<int, FileMutation> List of planned file mutations.
     * @throws \DomainException If Phii is executed in production without explicit permission.
     * @throws \InvalidArgumentException If required parameters are missing or generator type is invalid.
     */
    private function planMutations(string $generator, array $body, string $basePath): array
    {
        $isProd = (method_exists($this->app, 'environment') && $this->app->environment() === 'production')
            || (method_exists($this->app, 'isProduction') && $this->app->isProduction());
        $allowProd = (bool) ($this->config->phii['allowInProduction'] ?? false);

        if ($isProd && !$allowProd) {
            throw new \DomainException("Phii is disabled in production");
        }

        $entity = $body['entity'] ?? null;
        $module = $body['module'] ?? 'admin';
        $options = $body['options'] ?? [];

        if ($generator !== 'module' && $entity === null) {
            throw new \InvalidArgumentException('Missing entity for generator.');
        }

        $mutations = [];

        switch ($generator) {
            case 'model':
                $schema = $this->schemaRegistry->toPhiiExportSchema($entity, $module);
                $type = $options['type'] ?? 'active-record';
                $mutations = $this->modelGenerator->generateFromSchema($schema, $type, "{$basePath}/App/Models");
                break;

            case 'controller':
                $modelClass = $this->resolveModelClass($entity);
                $modelName = (new \ReflectionClass($modelClass))->getShortName();
                $actions = $options['actions'] ?? ['index', 'store', 'show', 'update', 'delete'];
                $mutations = $this->controllerGenerator->generate($module, $modelName, "{$basePath}/App/Modules/{$module}/Controller", $actions);
                break;

            case 'migration':
                $schema = $this->schemaRegistry->toPhiiExportSchema($entity, $module);
                $driver = $options['driver'] ?? 'sqlite';
                $mutations = $this->migrationGenerator->generateFromSchema($schema, "{$basePath}/database/migrations", $driver);
                break;

            case 'crud':
                $schema = $this->schemaRegistry->toPhiiExportSchema($entity, $module);
                $mutations = $this->crudGenerator->generate($schema, $module, $basePath);
                break;

            case 'module':
                $moduleName = $entity ?? ($options['name'] ?? 'NewModule');
                $modOptions = [
                    'auth' => (bool) ($options['auth'] ?? false),
                    'api' => (bool) ($options['api'] ?? false),
                ];
                $mutations = $this->moduleGenerator->generate($moduleName, $basePath, $modOptions);
                break;

            default:
                throw new \InvalidArgumentException("Unsupported generator type: '{$generator}'.");
        }

        return $mutations;
    }

    /**
     * Resolve fully qualified class name (FQCN) for a model input.
     *
     * @param string $input Model name or class string.
     * @return string Resolved fully qualified class name.
     * @throws \InvalidArgumentException If the model class cannot be resolved.
     */
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