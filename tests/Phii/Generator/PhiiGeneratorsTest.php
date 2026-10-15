<?php

declare(strict_types=1);

namespace Phue\Tests\Phii\Generator;

use Phue\Modules\Phii\Generator\ControllerGenerator;
use Phue\Modules\Phii\Generator\CrudGenerator;
use Phue\Modules\Phii\Generator\FileMutation;
use Phue\Modules\Phii\Generator\MigrationGenerator;
use Phue\Modules\Phii\Generator\ModelGenerator;
use Phue\Modules\Phii\Generator\ModuleGenerator;
use Phue\Modules\Schii\Generator\PhiiExportField;
use Phue\Modules\Schii\Generator\PhiiExportSchema;
use Phue\Modules\Schii\Schema\FieldSchema;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Schii\Stubs\Profile;
use PHPUnit\Framework\TestCase;

/**
 * Phii code generators (Model/Controller/Migration/Module/Crud/Api):
 * every generated artifact is a FileMutation describing real, parseable PHP
 * that reflects the schema producing it. No placeholders may survive,
 * no invalid syntax may escape, and file writes must be lazy + side-effect free
 * until write() is explicitly called.
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

    private function assertMutation(
        FileMutation $mutation,
        string $path,
        int $minLength = 1
    ): void {
        $this->assertSame($path, $mutation->path);
        $this->assertGreaterThanOrEqual($minLength, strlen($mutation->content));
        $this->assertStringNotContainsString('{{ ', $mutation->content, 'No template placeholder may survive.');
    }

    private function assertPhpIsValid(string $file): void
    {
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, 'Generated file must be valid PHP: ' . implode("\n", (array) $output));
    }

    public function testMutationIsLazyAndWritable(): void
    {
        $target = $this->dump . '/lazy-target.php';
        $mutation = new FileMutation($target, '<?php echo "hi";');

        // Generating a mutation must never touch the filesystem
        $this->assertFileDoesNotExist($target);
        $this->assertDirectoryDoesNotExist($this->dump);

        $this->assertTrue($mutation->write());
        $this->assertFileExists($target);
        $this->assertSame('<?php echo "hi";', file_get_contents($target));
    }

    public function testModelGeneratorActiveRecordProducesSchemaDrivenMutation(): void
    {
        [$mutation] = (new ModelGenerator())->generateFromSchema($this->schema(), 'active-record', $this->dump);

        $this->assertMutation($mutation, $this->dump . '/Profile.php');
        $this->assertTrue($mutation->write());
        $this->assertPhpIsValid($mutation->path);

        $content = $mutation->content;
        $this->assertStringContainsString('namespace Phue\Tests\Schii\Stubs;', $content);
        $this->assertStringContainsString('class Profile', $content);
        $this->assertStringContainsString("protected string \$table = 'profiles';", $content);
        $this->assertStringContainsString('public int $id;', $content);
        $this->assertStringContainsString('public string $full_name;', $content);
        $this->assertStringContainsString('public string $email;', $content);
        $this->assertStringContainsString('public bool $active;', $content);
        $this->assertStringContainsString('#[Field(', $content);
    }

    public function testModelGeneratorDataMapperUsesItsOwnTemplate(): void
    {
        [$mutation] = (new ModelGenerator())->generateFromSchema($this->schema(), 'data-mapper', $this->dump);

        $this->assertMutation($mutation, $this->dump . '/Profile.php');
        $this->assertTrue($mutation->write());
        $this->assertPhpIsValid($mutation->path);

        $this->assertStringContainsString('class Profile', $mutation->content);
        $this->assertStringNotContainsString('BaseActiveRecord', $mutation->content);
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

        [$mutation] = (new ModelGenerator())->generateFromFields($fields, 'Widget', 'widgets', 'App\\Widgets', $this->dump);

        $this->assertMutation($mutation, $this->dump . '/Widget.php');
        $this->assertTrue($mutation->write());
        $this->assertPhpIsValid($mutation->path);

        $content = $mutation->content;
        $this->assertStringContainsString('namespace App\Widgets;', $content);
        $this->assertStringContainsString('class Widget', $content);
        $this->assertStringContainsString("protected string \$table = 'widgets';", $content);
        $this->assertStringContainsString('public string $code;', $content);
        $this->assertStringContainsString('public float $weight;', $content);
    }

    /**
     * The generator must not mint a model its own ORM cannot hydrate.
     *
     * The defect
     * ----------
     * baseBuildProperties() mapped the PHP type with
     *
     *     'int' => 'int', 'bool' => 'bool', 'float' => 'float', default => 'string'
     *
     * and 'json' and 'datetime' both fell into that default. BaseActiveRecord
     * casts those two to an array and a DateTimeImmutable respectively, so every
     * generated model carrying a json or a datetime column was born broken:
     *
     *     TypeError: Cannot assign DateTimeImmutable to property
     *     App\Models\Comment::$created_at of type string
     *
     * raised by reflection, from a file nobody had edited, naming nothing about
     * where the declaration came from.
     *
     * Why the initialiser is part of the assertion
     * --------------------------------------------
     * A typed property with no default is UNINITIALISED, and a nullable DATETIME
     * column is the ordinary case. Emitting `public \DateTimeImmutable $at;`
     * would only have traded the TypeError for "must not be accessed before
     * initialization" the first time anything read the model before filling it.
     * The scalars are left exactly as they were; only the two declarations that
     * are new carry an initialiser.
     */
    public function testModelGeneratorEmitsDeclarationsTheOrmCanHydrate(): void
    {
        $fields = [
            PhiiExportField::fromFieldSchema(new FieldSchema(
                name: 'happened_at',
                type: 'datetime',
                label: 'Happened At',
                widget: 'datetime',
                column: true,
                required: false,
                searchable: true,
                filterable: true,
                sortable: true,
            )),
            PhiiExportField::fromFieldSchema(new FieldSchema(
                name: 'meta',
                type: 'json',
                label: 'Meta',
                widget: 'input',
                column: true,
                required: false,
                searchable: false,
                filterable: false,
                sortable: false,
            )),
            PhiiExportField::fromFieldSchema(new FieldSchema(
                name: 'born_on',
                type: 'date',
                label: 'Born On',
                widget: 'date',
                column: true,
                required: false,
                searchable: false,
                filterable: false,
                sortable: false,
            )),
        ];

        [$mutation] = (new ModelGenerator())->generateFromFields(
            $fields,
            'Event',
            'events',
            'App\\Models',
            $this->dump
        );

        $this->assertMutation($mutation, $this->dump . '/Event.php');
        $this->assertTrue($mutation->write());
        $this->assertPhpIsValid($mutation->path);

        $content = $mutation->content;

        $this->assertStringContainsString(
            "#[Field(type: 'datetime', required: false)]",
            $content,
            'The #[Field] type describes the COLUMN and must stay datetime — that is what the migrator and the schema diff read.',
        );
        $this->assertStringContainsString(
            'public ?\DateTimeImmutable $happened_at = null;',
            $content,
            'A public string property here is exactly the declaration that crashed hydration.',
        );
        $this->assertStringContainsString(
            'public array $meta = [];',
            $content,
            'json casts to an array; a public string property is the same fatal with a different class.',
        );
        $this->assertStringContainsString(
            'public string $born_on;',
            $content,
            "'date' has no cast in getFieldMeta(), so it is genuinely a string and must stay one.",
        );
    }

    /**
     * The scalars must not have moved.
     *
     * The fix above adds two branches. This pins that it did not quietly widen:
     * a generated `public int $id;` that became `public ?int $id = null;` would
     * be a silent change to every model the generator has ever produced.
     */
    public function testModelGeneratorLeavesScalarDeclarationsUntouched(): void
    {
        [$mutation] = (new ModelGenerator())->generateFromSchema($this->schema(), 'active-record', $this->dump);

        $content = $mutation->content;

        foreach (['public int $id;', 'public string $full_name;', 'public bool $active;'] as $declaration) {
            $this->assertStringContainsString(
                $declaration,
                $content,
                $declaration . ' is existing output. Changing it is a separate decision, not a side effect of fixing json and datetime.',
            );
        }

        $this->assertStringNotContainsString(' = ', $this->scalarLines($content));
    }

    /**
     * Every `public <scalar> $x;` line in a generated model.
     *
     * @param string $content
     * @return string
     */
    private function scalarLines(string $content): string
    {
        preg_match_all('/^\s*public (?:int|string|bool|float) \$[^;]+;$/m', $content, $matches);

        return implode("\n", $matches[0]);
    }

    public function testControllerGeneratorProducesRoutableControllerWithCorrectHttpVerbs(): void
    {
        [$mutation] = (new ControllerGenerator())->generate('admin', 'Profile', $this->dump, ['index', 'store', 'show', 'update', 'delete']);

        $this->assertMutation($mutation, $this->dump . '/ProfileController.php');
        $this->assertTrue($mutation->write());
        $this->assertPhpIsValid($mutation->path);

        $content = $mutation->content;
        $this->assertStringContainsString('namespace Phue\admin\Controller;', $content);
        $this->assertStringContainsString('class ProfileController', $content);
        $this->assertStringContainsString("#[Route('/admin/profile', methods: ['GET']", $content);
        $this->assertStringContainsString("#[Route('/admin/profile', methods: ['POST']", $content);
        $this->assertStringContainsString("#[Route('/admin/profile/{id}', methods: ['GET']", $content);
        $this->assertStringContainsString("#[Route('/admin/profile/{id}', methods: ['PUT']", $content);
        $this->assertStringContainsString("#[Route('/admin/profile/{id}', methods: ['DELETE']", $content);
        $this->assertStringContainsString('public function index(Request $request, mixed $id = null): Response', $content);
        $this->assertStringContainsString('public function show(Request $request, mixed $id = null): Response', $content);
        $this->assertStringContainsString('public function store(Request $request, mixed $id = null): Response', $content);
        $this->assertStringContainsString('public function update(Request $request, mixed $id = null): Response', $content);
        $this->assertStringContainsString('public function delete(Request $request, mixed $id = null): Response', $content);
        $this->assertStringContainsString('return Response::json', $content);
    }

    public function testControllerGeneratorHonorsRequestedActionSubset(): void
    {
        [$mutation] = (new ControllerGenerator())->generate('admin', 'Profile', $this->dump, ['show']);
        $this->assertTrue($mutation->write());
        $this->assertPhpIsValid($mutation->path);
        $content = $mutation->content;
        $this->assertStringContainsString('public function show(Request', $content);
        $this->assertStringNotContainsString('public function index(', $content);
        $this->assertStringNotContainsString('public function store(', $content);
        $this->assertStringNotContainsString('public function update(', $content);
        $this->assertStringNotContainsString('public function delete(', $content);
    }

    public function testModuleGeneratorSkeletonReplacesPlaceholdersAndWritesCleanly(): void
    {
        $mutations = (new ModuleGenerator())->generate('Blog', $this->dump);

        $this->assertCount(5, $mutations);

        $configMutation = null;
        $gitkeeps = [];
        foreach ($mutations as $mutation) {
            if (str_ends_with($mutation->path, '/Config/module.php')) {
                $configMutation = $mutation;
            }
            if (str_ends_with($mutation->path, '/.gitkeep')) {
                $gitkeeps[] = $mutation;
            }
        }

        $this->assertNotNull($configMutation, 'Config/module.php mutation is required');
        $this->assertCount(4, $gitkeeps, 'Exactly 4 .gitkeep mutations expected (Config/Controller/Views/Model)');

        foreach (['Config', 'Controller', 'Views', 'Model'] as $sub) {
            $this->assertContains($this->dump . "/App/Modules/Blog/{$sub}/.gitkeep", array_map(
                static fn (FileMutation $m): string => $m->path,
                $gitkeeps
            ));
        }

        $content = $configMutation->content;
        $this->assertStringContainsString('Blog', $content);
        $this->assertStringNotContainsString('{{ MODULE_NAME }}', $content);

        // Writing every mutation must create the skeleton lazily
        foreach (array_merge([$configMutation], $gitkeeps) as $mutation) {
            $this->assertFileDoesNotExist($mutation->path);
        }
        $this->assertTrue($configMutation->write());
        $this->assertFileExists($this->dump . '/App/Modules/Blog/Config/module.php');
    }

    public function testMigrationGeneratorProducesSqlMigration(): void
    {
        [$mutation] = (new MigrationGenerator())->generateFromSchema($this->schema(), $this->dump, 'sqlite');

        $this->assertStringMatchesFormat('m%a_create_profiles.php', basename($mutation->path));
        $this->assertTrue($mutation->write());
        $this->assertPhpIsValid($mutation->path);

        $content = $mutation->content;
        $this->assertStringContainsString('use Phue\Core\Database\Migration;', $content);
        $this->assertMatchesRegularExpression('/final class M[\d_]+CreateProfiles extends Migration/', $content);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $content);
        $this->assertStringContainsString('`profiles`', $content);
        $this->assertStringContainsString('PRIMARY KEY', $content);
        $this->assertStringContainsString("\$this->execute(\"DROP TABLE IF EXISTS `profiles`;\");", $content);
        $this->assertStringNotContainsString('{{ ', $content);
    }

    public function testMigrationGeneratorEmitsDriverSpecificSql(): void
    {
        [$mutation] = (new MigrationGenerator())->generateFromSchema($this->schema(), $this->dump . '/pg', 'pgsql');

        $this->assertTrue($mutation->write());
        $this->assertPhpIsValid($mutation->path);

        $content = $mutation->content;
        // Postgres identifiers are double-quoted: escaping must keep the file parseable.
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $content);
        $this->assertStringContainsString('SERIAL PRIMARY KEY', $content);
        $this->assertStringContainsString('\\"profiles\\"', $content);
        $this->assertStringNotContainsString('`', $content);
        $this->assertStringNotContainsString('AUTOINCREMENT', $content);
        $this->assertStringNotContainsString('{{ ', $content);
    }

    public function testCrudGeneratorReportsAllArtifactTypes(): void
    {
        $crud = new CrudGenerator();
        $this->assertSame(['model', 'controller', 'migration'], $crud->getGeneratedTypes());
    }

    public function testCrudGeneratorScaffoldsModelControllerAndMigrationAsMutations(): void
    {
        $mutations = (new CrudGenerator())->generate($this->schema(), 'admin', $this->dump);

        $this->assertCount(3, $mutations);

        $paths = array_map(static fn (FileMutation $m): string => $m->path, $mutations);
        $this->assertContains($this->dump . '/App/Models/Profile.php', $paths);
        $this->assertContains($this->dump . '/App/Modules/admin/Controller/ProfileController.php', $paths);
        $this->assertNotEmpty(array_filter($paths, static fn (string $p): bool =>
            str_contains($p, '/database/migrations/') && str_ends_with($p, '_create_profiles.php')
        ));

        foreach ($mutations as $mutation) {
            $this->assertFileDoesNotExist($mutation->path, 'Generation must remain lazy until write()');
        }

        foreach ($mutations as $mutation) {
            $this->assertTrue($mutation->write());
            $this->assertFileExists($mutation->path);
            $this->assertPhpIsValid($mutation->path);
        }
    }
}