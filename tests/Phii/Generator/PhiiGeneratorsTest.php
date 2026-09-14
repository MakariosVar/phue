<?php

declare(strict_types=1);

namespace Phue\Tests\Phii\Generator;

use Phue\Generator\ApiGenerator;
use Phue\Generator\ControllerGenerator;
use Phue\Generator\CrudGenerator;
use Phue\Generator\MigrationGenerator;
use Phue\Generator\ModelGenerator;
use Phue\Generator\ModuleGenerator;
use Phue\Schii\Generator\PhiiExportField;
use Phue\Schii\Generator\PhiiExportSchema;
use Phue\Schii\Schema\FieldSchema;
use Phue\Schii\Schema\SchemaRegistry;
use Phue\Tests\Schii\Stubs\Profile;
use PHPUnit\Framework\TestCase;

/**
 * Phii code generators (Model/Controller/Migration/Module/Crud/Api):
 * every generated artifact must be real, parseable PHP and reflect the schema
 * that produced it. No placeholders may survive, no invalid syntax may escape.
 *
 * @package Phue\Tests\Phii\Generator
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhiiGeneratorsTest extends TestCase
{
    private string $dump;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dump = sys_get_temp_dir() . '/phue_phii_gen_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->dump);
        parent::tearDown();
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function schema(): PhiiExportSchema
    {
        return (new SchemaRegistry())->toPhiiExportSchema(Profile::class, 'admin');
    }

    private function assertPhpIsValid(string $file): void
    {
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, 'Generated file must be valid PHP: ' . implode("\n", (array) $output));
    }

    public function testModelGeneratorActiveRecordProducesLoadableSchemaDrivenFile(): void
    {
        $file = (new ModelGenerator())->generateFromSchema($this->schema(), 'active-record', $this->dump);

        $this->assertSame($this->dump . '/Profile.php', $file);
        $this->assertFileExists($file);
        $this->assertPhpIsValid($file);

        $content = (string) file_get_contents($file);
        $this->assertStringContainsString('namespace Phue\Tests\Schii\Stubs;', $content);
        $this->assertStringContainsString('class Profile', $content);
        $this->assertStringContainsString("protected string \$table = 'profiles';", $content);
        $this->assertStringContainsString('#[Field(type: \'int\', required: false, searchable: false, filterable: false, sortable: false)]', $content);
        $this->assertStringContainsString('public int $id;', $content);
        $this->assertStringContainsString('public string $full_name;', $content);
        $this->assertStringContainsString('public bool $active;', $content);
        $this->assertStringNotContainsString('{{ ', $content);
    }

    public function testModelGeneratorDataMapperUsesItsOwnTemplate(): void
    {
        $file = (new ModelGenerator())->generateFromSchema($this->schema(), 'data-mapper', $this->dump);

        $this->assertFileExists($file);
        $this->assertPhpIsValid($file);

        $content = (string) file_get_contents($file);
        $this->assertStringContainsString('class Profile', $content);
        $this->assertStringNotContainsString('BaseActiveRecord', $content);
        $this->assertStringNotContainsString('{{ ', $content);
    }

    public function testModelGeneratorFromFieldsProducesRequestedShape(): void
    {
        $fields = [
            PhiiExportField::fromFieldSchema(new FieldSchema(
                name: 'code',
                type: 'text',
                label: 'Code',
                widget: 'input',
                column: true,
                required: true,
                searchable: true,
                filterable: false,
                sortable: false,
            )),
            PhiiExportField::fromFieldSchema(new FieldSchema(
                name: 'weight',
                type: 'float',
                label: 'Weight',
                widget: 'input',
                column: true,
                required: false,
                searchable: false,
                filterable: true,
                sortable: false,
            )),
        ];

        $file = (new ModelGenerator())->generateFromFields($fields, 'Widget', 'widgets', 'App\\Widgets', $this->dump);

        $this->assertSame($this->dump . '/Widget.php', $file);
        $this->assertPhpIsValid($file);

        $content = (string) file_get_contents($file);
        $this->assertStringContainsString('namespace App\Widgets;', $content);
        $this->assertStringContainsString('class Widget', $content);
        $this->assertStringContainsString("protected string \$table = 'widgets';", $content);
        $this->assertStringContainsString('public string $code;', $content);
        $this->assertStringContainsString('public float $weight;', $content);
    }

    public function testControllerGeneratorProducesRoutableControllerWithCorrectHttpVerbs(): void
    {
        $generator = new ControllerGenerator();
        $file = $generator->generate('admin', 'Profile', $this->dump, ['index', 'store', 'show', 'update', 'delete']);

        $this->assertSame($this->dump . '/ProfileController.php', $file);
        $this->assertPhpIsValid($file);

        $content = (string) file_get_contents($file);
        $this->assertStringContainsString('class ProfileController', $content);
        $this->assertStringContainsString("#[Route('/admin/profile', methods: ['GET']", $content);
        $this->assertStringContainsString("#[Route('/admin/profile', methods: ['POST']", $content);
        $this->assertStringContainsString("#[Route('/admin/profile/{id}', methods: ['GET']", $content);
        $this->assertStringContainsString("#[Route('/admin/profile/{id}', methods: ['PUT']", $content);
        $this->assertStringContainsString("#[Route('/admin/profile/{id}', methods: ['DELETE']", $content);
        $this->assertStringContainsString('public function index(Request $request, mixed $id = null): Response', $content);
        $this->assertStringContainsString('return Response::json', $content);
    }

    public function testControllerGeneratorHonorsRequestedActionSubset(): void
    {
        $file = (new ControllerGenerator())->generate('admin', 'Profile', $this->dump, ['show']);

        $this->assertPhpIsValid($file);
        $content = (string) file_get_contents($file);
        $this->assertStringContainsString("public function show(Request", $content);
        $this->assertStringNotContainsString('public function index(', $content);
        $this->assertStringNotContainsString('public function store(', $content);
    }

    public function testModuleGeneratorCreatesDirectorySkeletonAndConfig(): void
    {
        $created = (new ModuleGenerator())->generate('Blog', $this->dump, ['auth' => true, 'api' => true]);

        foreach (['Config', 'Controller', 'Model', 'Views'] as $sub) {
            $this->assertDirectoryExists($this->dump . "/App/Modules/Blog/{$sub}");
        }

        $configFile = $this->dump . '/App/Modules/Blog/Config/module.php';
        $this->assertFileExists($configFile);
        $this->assertStringContainsString('Blog', (string) file_get_contents($configFile));
        $this->assertStringNotContainsString('{{ MODULE_NAME }}', (string) file_get_contents($configFile));
        $this->assertContains($configFile, $created, 'Config file must be reported as created');
    }

    public function testMigrationGeneratorProducesSqlMigration(): void
    {
        $migration = new MigrationGenerator();
        $file = $migration->generateFromSchema($this->schema(), $this->dump, 'sqlite');

        $this->assertFileExists($file);
        $this->assertPhpIsValid($file);

        $content = (string) file_get_contents($file);
        $this->assertStringContainsString("\$this->schema->create('profiles', function (Blueprint \$table)", $content);
        $this->assertStringContainsString('$table->id();', $content);
        $this->assertStringContainsString("\$table->text('full_name')", $content);
        $this->assertStringContainsString("\$table->boolean('active')", $content);
        $this->assertStringContainsString("\$this->schema->dropIfExists('profiles');", $content);
        $this->assertStringNotContainsString('{{ ', $content);
    }

    public function testMigrationGeneratorEmitsDialectNeutralBlueprintMigration(): void
    {
        $migration = new MigrationGenerator();
        $file = $migration->generateFromSchema($this->schema(), $this->dump . '/pg', 'pgsql');

        $this->assertFileExists($file);
        $this->assertPhpIsValid($file);

        $content = (string) file_get_contents($file);
        $this->assertStringContainsString("\$table->text('full_name')", $content);

        $this->assertStringNotContainsString('CREATE TABLE', $content);
        $this->assertStringNotContainsString('SERIAL PRIMARY KEY', $content);
        $this->assertStringNotContainsString('AUTOINCREMENT', $content);
        $this->assertStringNotContainsString('{{ ', $content);
    }

    public function testCrudGeneratorReportsAllArtifactTypes(): void
    {
        $crud = new CrudGenerator();
        $this->assertSame(['model', 'controller', 'migration', 'routes', 'views'], $crud->getGeneratedTypes());
    }

    public function testCrudGeneratorScaffoldsModelControllerAndMigration(): void
    {
        $files = (new CrudGenerator())->generate($this->schema(), 'admin', $this->dump);

        $this->assertArrayHasKey('model', $files);
        $this->assertArrayHasKey('controller', $files);
        $this->assertArrayHasKey('migration', $files);

        foreach ([$files['model'], $files['controller'], $files['migration']] as $artifact) {
            $this->assertFileExists($artifact);
            $this->assertPhpIsValid($artifact);
        }

        $this->assertStringContainsString('admin routes registered', $files['routes']);
        $this->assertStringContainsString('admin views scaffolded', $files['views']);
    }

    public function testApiGeneratorWritesControllerAndRouteSnippet(): void
    {
        $result = (new ApiGenerator())->generate($this->schema(), $this->dump);

        $this->assertSame(['api_controller', 'routes'], array_keys($result));
        $this->assertFileExists($result['api_controller']);
        $this->assertPhpIsValid($result['api_controller']);

        $this->assertStringContainsString('/api/v1/profile', $result['routes']);
        $this->assertStringContainsString('ProfileApiController', (string) file_get_contents($result['api_controller']));
    }
}