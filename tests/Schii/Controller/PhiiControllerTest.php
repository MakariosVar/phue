<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Controller;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Modules\Schii\Config\SchiiConfig;
use Phue\Modules\Schii\Controller\PhiiController;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Schii\Stubs\Profile;
use PHPUnit\Framework\TestCase;

/**
 * PhiiController generate/preview/apply contract: JSON responses, lazy
 * FileMutation planning, atomic writing, production safety gates and
 * per-generator dispatch to the real file layout of a Phue application.
 *
 * @package Phue\Tests\Schii\Controller
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhiiControllerTest extends TestCase
{
    private string $dump;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dump = sys_get_temp_dir() . '/phue_phii_ctrl_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dump)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dump, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink()
                    ? rmdir($item->getPathname())
                    : unlink($item->getPathname());
            }
            rmdir($this->dump);
        }

        parent::tearDown();
    }

    private function controller(array $config = []): PhiiController
    {
        $config = SchiiConfig::fromArray(array_merge([
            'apiPrefix' => '/api/schii',
            'entityNamespace' => 'Phue\\Tests\\Schii\\Stubs',
            'exportDir' => $this->dump,
        ], $config));

        return new PhiiController(
            $this->createMock(\Phue\Core\UI\ComponentRenderer::class),
            new SchemaRegistry(),
            $config,
            $this->createMock(Application::class)
        );
    }

    private function prodController(array $config = []): PhiiController
    {
        $app = new class($this->dump) extends Application {
            public function environment(): string
            {
                return 'production';
            }
        };

        $config = SchiiConfig::fromArray(array_merge([
            'apiPrefix' => '/api/schii',
            'entityNamespace' => 'Phue\\Tests\\Schii\\Stubs',
            'exportDir' => $this->dump,
        ], $config));

        return new PhiiController(
            $this->createMock(\Phue\Core\UI\ComponentRenderer::class),
            new SchemaRegistry(),
            $config,
            $app
        );
    }

    public function testGenerateRejectsMissingEntity(): void
    {
        $request = new Request('POST', '/phii/generate', [], ['generator' => 'model']);
        $response = $this->controller()->generate($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Missing entity for generator.', $data['error']);
    }

    public function testGenerateRejectsUnknownGenerator(): void
    {
        $request = new Request('POST', '/phii/generate', [], ['generator' => 'nope', 'entity' => Profile::class]);
        $response = $this->controller()->generate($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame("Unsupported generator type: 'nope'.", $data['error']);
    }

    public function testGenerateModelArtifact(): void
    {
        $request = new Request('POST', '/phii/generate', [], [
            'generator' => 'model',
            'entity' => Profile::class,
            'module' => 'admin',
            'options' => ['type' => 'active-record'],
        ]);

        $response = $this->controller()->generate($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $path = "{$this->dump}/App/Models/Profile.php";
        $this->assertSame([$path], $data['paths']);
        $this->assertFileExists($path);
        $this->assertStringContainsString('class Profile', (string) file_get_contents($path));
    }

    public function testGenerateControllerArtifact(): void
    {
        $request = new Request('POST', '/phii/generate', [], [
            'generator' => 'controller',
            'entity' => Profile::class,
            'module' => 'admin',
            'options' => ['actions' => ['index', 'show']],
        ]);

        $response = $this->controller()->generate($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $path = "{$this->dump}/App/Modules/admin/Controller/ProfileController.php";
        $this->assertContains($path, $data['paths']);
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('class ProfileController', $content);
        $this->assertStringContainsString('public function index(', $content);
        $this->assertStringContainsString('public function show(', $content);
        $this->assertStringNotContainsString('public function store(', $content);
    }

    public function testGenerateModuleArtifact(): void
    {
        $request = new Request('POST', '/phii/generate', [], [
            'generator' => 'module',
            'entity' => 'Blog',
        ]);

        $response = $this->controller()->generate($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $configPath = "{$this->dump}/App/Modules/Blog/Config/module.php";
        $this->assertContains($configPath, $data['paths']);
        $this->assertFileExists($configPath);

        // The full lazy skeleton (Config/Controller/Views/Model .gitkeep) must be reported
        foreach (['Config', 'Controller', 'Views', 'Model'] as $sub) {
            $this->assertFileExists("{$this->dump}/App/Modules/Blog/{$sub}/.gitkeep");
        }
        $this->assertCount(5, $data['paths']);
    }

    public function testGenerateMigrationArtifact(): void
    {
        $request = new Request('POST', '/phii/generate', [], [
            'generator' => 'migration',
            'entity' => Profile::class,
            'module' => 'admin',
            'options' => ['driver' => 'sqlite'],
        ]);

        $response = $this->controller()->generate($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $created = glob("{$this->dump}/database/migrations/*_create_profiles.php");
        $this->assertNotEmpty($created);
        $this->assertSame([$created[0]], $data['paths']);
        $this->assertStringContainsString("create('profiles'", (string) file_get_contents($created[0]));
    }

    public function testGenerateCrudArtifact(): void
    {
        $request = new Request('POST', '/phii/generate', [], [
            'generator' => 'crud',
            'entity' => Profile::class,
            'module' => 'admin',
        ]);

        $response = $this->controller()->generate($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertContains("{$this->dump}/App/Models/Profile.php", $data['paths']);
        $this->assertContains("{$this->dump}/App/Modules/admin/Controller/ProfileController.php", $data['paths']);
        $this->assertCount(3, $data['paths']);

        $this->assertFileExists("{$this->dump}/App/Models/Profile.php");
        $this->assertFileExists("{$this->dump}/App/Modules/admin/Controller/ProfileController.php");
        $this->assertNotEmpty(glob("{$this->dump}/database/migrations/*_create_profiles.php"));
    }

    // ─────────────────────────────── Preview ───────────────────────────────

    public function testPreviewNeverWritesToDisk(): void
    {
        $request = new Request('POST', '/phii/preview', [], [
            'generator' => 'model',
            'entity' => Profile::class,
            'module' => 'admin',
            'options' => ['type' => 'active-record'],
        ]);

        $response = $this->controller()->preview($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('model', $data['generator']);
        $this->assertCount(1, $data['plan']);
        $this->assertFileDoesNotExist("{$this->dump}/App/Models/Profile.php", 'Preview must be side-effect free');

        $planItem = $data['plan'][0];
        $this->assertSame("{$this->dump}/App/Models/Profile.php", $planItem['path']);
        $this->assertFalse($planItem['exists']);
        $this->assertFalse($planItem['willOverwrite']);
        $this->assertGreaterThan(0, $planItem['contentLength']);
        $this->assertStringContainsString('class Profile', $planItem['content']);
    }

    public function testPreviewFlagsExistingFilesAsWillOverwrite(): void
    {
        mkdir("{$this->dump}/App/Models", 0777, true);
        file_put_contents(
            "{$this->dump}/App/Models/Profile.php",
            '<?php // existing user-written file'
        );

        $request = new Request('POST', '/phii/preview', [], [
            'generator' => 'model',
            'entity' => Profile::class,
            'module' => 'admin',
            'options' => ['type' => 'active-record'],
        ]);

        $data = json_decode($this->controller()->preview($request)->getContent(), true);
        $planItem = $data['plan'][0];

        $this->assertTrue($planItem['exists']);
        $this->assertTrue($planItem['willOverwrite'], 'Phii must never hide an overwrite from the operator.');
    }

    public function testPreviewRejectsUnknownGenerator(): void
    {
        $request = new Request('POST', '/phii/preview', [], ['generator' => 'nope', 'entity' => Profile::class]);

        $response = $this->controller()->preview($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    // ─────────────────────────────── Apply ───────────────────────────────

    private function samplePlan(): array
    {
        return [[
            'path' => "{$this->dump}/App/Models/Profile.php",
            'content' => '<?php echo "generated";',
        ]];
    }

    public function testApplyWritesProvidedPlan(): void
    {
        $request = new Request('POST', '/phii/apply', [], ['plan' => $this->samplePlan()]);
        $response = $this->controller()->apply($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertSame(1, $data['written']);
        $this->assertSame(0, $data['skipped']);
        $this->assertSame(0, $data['conflicts']);
        $this->assertFileExists("{$this->dump}/App/Models/Profile.php");
    }

    public function testApplyCountsExistingFileAsConflict(): void
    {
        mkdir("{$this->dump}/App/Models", 0777, true);
        file_put_contents(
            "{$this->dump}/App/Models/Profile.php",
            '<?php // existing'
        );

        $request = new Request('POST', '/phii/apply', [], ['plan' => $this->samplePlan()]);
        $data = json_decode($this->controller()->apply($request)->getContent(), true);

        $this->assertSame(1, $data['written']);
        $this->assertSame(1, $data['conflicts'], 'Overwriting an existing file must be reported as conflict.');
    }

    public function testApplySkipsEntriesWithoutPath(): void
    {
        $request = new Request('POST', '/phii/apply', [], [
            'plan' => [['content' => 'no path provided']],
        ]);
        $data = json_decode($this->controller()->apply($request)->getContent(), true);

        $this->assertSame(0, $data['written']);
        $this->assertSame(1, $data['skipped']);
    }

    public function testApplyCreatesBackupWhenRequested(): void
    {
        mkdir($this->dump, 0777, true);

        $request = new Request('POST', '/phii/apply', [], [
            'plan' => $this->samplePlan(),
            'backup' => true,
        ]);

        $data = json_decode($this->controller()->apply($request)->getContent(), true);

        $this->assertIsString($data['backup']);
        $this->assertStringStartsWith('backup_', (string) basename((string) $data['backup']));
        $this->assertStringEndsWith('.zip', (string) $data['backup']);
    }

    public function testApplyWithoutBackupReturnsNoBackupPath(): void
    {
        $request = new Request('POST', '/phii/apply', [], ['plan' => $this->samplePlan()]);
        $data = json_decode($this->controller()->apply($request)->getContent(), true);

        $this->assertNull($data['backup']);
    }

    // ─────────────────────────────── Production gate ───────────────────────────────

    public function testApplyRejectedInProductionWithoutPermission(): void
    {
        $request = new Request('POST', '/phii/apply', [], ['plan' => $this->samplePlan()]);
        $response = $this->prodController()->apply($request);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Phii is disabled in production', $data['error']);
        $this->assertFileDoesNotExist("{$this->dump}/App/Models/Profile.php");
    }

    public function testGenerateRejectedInProductionWithoutPermission(): void
    {
        $request = new Request('POST', '/phii/generate', [], [
            'generator' => 'model',
            'entity' => Profile::class,
        ]);

        $response = $this->prodController()->generate($request);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPreviewRejectedInProductionWithoutPermission(): void
    {
        $request = new Request('POST', '/phii/preview', [], [
            'generator' => 'model',
            'entity' => Profile::class,
        ]);

        $response = $this->prodController()->preview($request);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testApplyAllowedInProductionWhenExplicitlyEnabled(): void
    {
        $request = new Request('POST', '/phii/apply', [], ['plan' => $this->samplePlan()]);
        $response = $this->prodController(['phii' => ['allowInProduction' => true]])->apply($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFileExists("{$this->dump}/App/Models/Profile.php");
    }
}