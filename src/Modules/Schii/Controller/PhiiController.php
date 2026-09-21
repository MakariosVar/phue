<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Controller;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\UI\ComponentRenderer;
use Phue\Modules\Phii\Generator\ControllerGenerator;
use Phue\Modules\Phii\Generator\CrudGenerator;
use Phue\Modules\Phii\Generator\FileMutation;
use Phue\Modules\Phii\Generator\MigrationGenerator;
use Phue\Modules\Phii\Generator\ModelGenerator;
use Phue\Modules\Phii\Generator\ModuleGenerator;
use Phue\Modules\Phii\Generator\BackupManager;
use Phue\Modules\Schii\Config\SchiiConfig;
use Phue\Modules\Schii\Schema\SchemaRegistry;

/**
 * Class PhiiController
 *
 * Handles Phii panel UI rendering, mutation planning, previewing, and atomic code generation application.
 *
 * @package Phue\Modules\Schii\Controller
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
            'phiiPrefix' => $this->config->phiiPrefix ?? '/phii',
            'phiiNamespace' => $this->config->phiiNamespace ?? 'Phue\\Phii',
            'module' => 'admin',
        ];

        return $this->renderer->render('Phii', ['state' => $state]);
    }

    /**
     * Execute direct file generation based on planned mutations with backup and conflict check.
     *
     * @param Request $request HTTP Request instance with parsed body data.
     * @return Response JSON response containing written file paths or error details.
     */
    public function generate(Request $request): Response
    {
        try {
            $this->assertProductionAccess();

            $body = (array) $request->getParsedBody();
            $generator = $body['generator'] ?? null;
            $force = (bool) ($body['force'] ?? false);
            $outputRoot = $this->resolveOutputRoot();

            $mutations = $this->planMutations((string) $generator, $body, $outputRoot);
            
            // Auto backup-by-default before write if requested or configured
            $doBackup = (bool) ($body['backup'] ?? true);
            if ($doBackup && !empty($mutations)) {
                $this->backupManager->createBackup($outputRoot);
            }

            $writtenPaths = [];
            foreach ($mutations as $mutation) {
                $safePath = $this->ensureContainment($mutation->path, $outputRoot);

                if (file_exists($safePath) && !$force) {
                    throw new \RuntimeException("Conflict: File already exists at '{$safePath}' and overwrite is disabled.");
                }

                $containedMutation = new FileMutation($safePath, $mutation->content);
                if ($containedMutation->write()) {
                    $writtenPaths[] = $safePath;
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
            } elseif ($e instanceof \RuntimeException) {
                $code = 409; // Conflict
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
            $this->assertProductionAccess();
            $body = (array) $request->getParsedBody();
            $generator = $body['generator'] ?? null;
            $entity = $body['entity'] ?? null;
            $outputRoot = $this->resolveOutputRoot();

            $mutations = $this->planMutations((string) $generator, $body, $outputRoot);
            $plan = [];

            foreach ($mutations as $mutation) {
                $safePath = $this->ensureContainment($mutation->path, $outputRoot);
                $exists = file_exists($safePath);
                
                $plan[] = [
                    'path' => $safePath,
                    'content' => $mutation->content,
                    'exists' => $exists,
                    'willOverwrite' => $exists,
                    'contentLength' => mb_strlen($mutation->content),
                    'summary' => 'Mutation for ' . basename($safePath),
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
     * Apply explicit file mutations from an execution plan with path containment and conflict resolution.
     *
     * @param Request $request HTTP Request instance containing the plan and options.
     * @return Response JSON response detailing written counts, skips, conflicts, and backup paths.
     */
    public function apply(Request $request): Response
    {
        try {
            $this->assertProductionAccess();

            $body = (array) $request->getParsedBody();
            $planInput = $body['plan'] ?? [];
            $doBackup = (bool) ($body['backup'] ?? false);
            $conflictPolicy = $body['conflictPolicy'] ?? 'overwrite';
            $outputRoot = $this->resolveOutputRoot();

            $written = 0;
            $skipped = 0;
            $conflicts = 0;
            $backupPath = null;

            if ($doBackup && !empty($planInput)) {
                $backupPath = $this->backupManager->createBackup($outputRoot);
            }

            foreach ($planInput as $item) {
                $rawPath = $item['path'] ?? null;
                $content = $item['content'] ?? null;

                if (!$rawPath) {
                    $skipped++;
                    continue;
                }

                $safePath = $this->ensureContainment($rawPath, $outputRoot);

                if (file_exists($safePath)) {
                    $conflicts++;
                    if ($conflictPolicy === 'skip') {
                        $skipped++;
                        continue;
                    }
                }

                $mutation = new FileMutation($safePath, (string) $content);
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
                403,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Resolve output root directory securely, preventing writes to phantom trees.
     *
     * @return string Resolved absolute base path or configured output root.
     */
    private function resolveOutputRoot(): string
    {
        $configuredRoot = ($this->config->outputRoot !== '')
            ? $this->config->outputRoot
            : (!empty($this->config->exportDir) ? $this->config->exportDir : null);
        if ($configuredRoot) {
            return rtrim($configuredRoot, DIRECTORY_SEPARATOR);
        }

        $storageGenerated = $this->app->getBasePath();

        if (!is_dir($storageGenerated)) {
            @mkdir($storageGenerated, 0755, true);
        }

        return $storageGenerated;
    }

    /**
     * Enforce strict path containment within the allowed output root to prevent path traversal.
     *
     * @param string $path Target path to evaluate.
     * @param string $outputRoot Allowed base root directory.
     * @return string Canonical safe path.
     * @throws \DomainException If path escapes the container root.
     */
    private function ensureContainment(string $path, string $outputRoot): string
    {
        // If relative path given, append to outputRoot
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
            $path = $outputRoot . DIRECTORY_SEPARATOR . $path;
        }

        $realRoot = realpath($outputRoot) ?: $outputRoot;

        // Find the nearest existing ancestor directory
        $current = dirname($path);
        while ($current !== false && !file_exists($current)) {
            $parent = dirname($current);
            if ($parent === $current) {
                break; // Reached the root without finding an existing directory
            }
            $current = $parent;
        }

        $realDir = realpath($current);

        // Validate that the nearest existing ancestor resolves and is contained within $realRoot
        if ($realDir === false || !str_starts_with($realDir, $realRoot)) {
            throw new \DomainException("Security Violation: Path traversal attempt detected for '{$path}'.");
        }

        // Return safe canonical path combining the real existing base directory, 
        // the remaining relative parts, and the basename.
        return dirname($path) . DIRECTORY_SEPARATOR . basename($path);
    }

    /**
     * Verify whether environment permits Phii generation execution.
     *
     * @return void
     * @throws \DomainException If in production and not allowed.
     */
    private function assertProductionAccess(): void
    {
        $appEnv = (method_exists($this->app, 'environment') && $this->app->environment() !== '')
            ? $this->app->environment()
            : ($_ENV['APP_ENV'] ?? (getenv('APP_ENV') ?: 'development'));

        $isProd = strtolower((string) $appEnv) === 'production';
        $allowProd = (bool) ($this->config->phii['allowInProduction'] ?? false);

        if ($isProd && !$allowProd) {
            throw new \DomainException("Phii code generation is disabled in production environments.");
        }
    }

    /**
     * Plan file mutations and generate code structures.
     *
     * @param string               $generator Generator type identifier.
     * @param array<string, mixed> $body      Request body parameters.
     * @param string               $outputRoot Safe output base path.
     * @return array<int, FileMutation> List of planned file mutations.
     * @throws \InvalidArgumentException If required parameters are missing or generator type is invalid.
     */
    private function planMutations(string $generator, array $body, string $outputRoot): array
    {
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
                $mutations = $this->modelGenerator->generateFromSchema($schema, $type, "{$outputRoot}/App/Models");
                break;

            case 'controller':
                $modelClass = $this->resolveModelClass($entity);
                $modelName = (new \ReflectionClass($modelClass))->getShortName();
                $actions = $options['actions'] ?? ['index', 'store', 'show', 'update', 'delete'];
                $mutations = $this->controllerGenerator->generate($module, $modelName, "{$outputRoot}/App/Modules/{$module}/Controller", $actions);
                break;

            case 'migration':
                $schema = $this->schemaRegistry->toPhiiExportSchema($entity, $module);
                $driver = $options['driver'] ?? 'sqlite';
                $mutations = $this->migrationGenerator->generateFromSchema($schema, "{$outputRoot}/database/migrations", $driver);
                break;

            case 'crud':
                $schema = $this->schemaRegistry->toPhiiExportSchema($entity, $module);
                $mutations = $this->crudGenerator->generate($schema, $module, $outputRoot);
                break;

            case 'module':
                $moduleName = $entity ?? ($options['name'] ?? 'NewModule');
                $modOptions = [
                    'auth' => (bool) ($options['auth'] ?? false),
                    'api' => (bool) ($options['api'] ?? false),
                ];
                $mutations = $this->moduleGenerator->generate($moduleName, $outputRoot, $modOptions);
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