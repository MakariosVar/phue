<?php

declare(strict_types=1);

namespace Phue\Generator;

use Phue\Generator\FileMutation;
use Phue\Schii\Generator\PhiiExportSchema;

/**
 * Orchestrator generator that coordinates Model, Controller, Migration, and Routes file mutations.
 *
 * @package Phue\Generator
 */
final class CrudGenerator
{
    private ModelGenerator $modelGenerator;
    private ControllerGenerator $controllerGenerator;
    private MigrationGenerator $migrationGenerator;

    public function __construct(
        ?ModelGenerator $modelGenerator = null,
        ?ControllerGenerator $controllerGenerator = null,
        ?MigrationGenerator $migrationGenerator = null
    ) {
        $this->modelGenerator = $modelGenerator ?? new ModelGenerator();
        $this->controllerGenerator = $controllerGenerator ?? new ControllerGenerator();
        $this->migrationGenerator = $migrationGenerator ?? new MigrationGenerator();
    }

    /**
     * Get list of generated artifact types.
     *
     * @return array<int, string>
     */
    public function getGeneratedTypes(): array
    {
        return ['model', 'controller', 'migration', 'routes', 'views'];
    }

    /**
     * Generate full CRUD scaffold file mutations for a Schii export schema.
     *
     * @param PhiiExportSchema $schema   Target schema export.
     * @param string           $module   Target module name.
     * @param string           $basePath Project base path.
     *
     * @return array<int, FileMutation> List of file mutations.
     */
    public function generate(
        PhiiExportSchema $schema,
        string $module = 'admin',
        string $basePath = ''
    ): array {
        $modelMutations = $this->modelGenerator->generateFromSchema($schema, 'active-record', "{$basePath}/App/Models");
        $controllerMutations = $this->controllerGenerator->generate($module, $schema->entity, "{$basePath}/App/Modules/{$module}/Controller");
        $migrationMutations = $this->migrationGenerator->generateFromSchema($schema, "{$basePath}/database/migrations");

        return array_merge(
            $modelMutations,
            $controllerMutations,
            $migrationMutations
        );
    }
}