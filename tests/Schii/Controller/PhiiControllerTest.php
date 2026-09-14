<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Controller;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Schii\Config\SchiiConfig;
use Phue\Schii\Controller\PhiiController;
use Phue\Schii\Schema\SchemaRegistry;
use Phue\Tests\Schii\Stubs\Profile;
use PHPUnit\Framework\TestCase;

/**
 * PhiiController::generate() contract: JSON responses, path list of created
 * artifacts, proper validation errors and per-generator dispatch.
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

    private function controller(): PhiiController
    {
        $config = SchiiConfig::fromArray([
            'apiPrefix' => '/api/schii',
            'entityNamespace' => 'Phue\\Tests\\Schii\\Stubs',
            'exportDir' => $this->dump,
        ]);

        return new PhiiController(
            $this->createMock(\Phue\Core\UI\ComponentRenderer::class),
            new SchemaRegistry(),
            $config,
            $this->createMock(Application::class)
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
        $this->assertSame([$this->dump . '/Profile.php'], $data['paths']);
        $this->assertFileExists($this->dump . '/Profile.php');
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
        $this->assertFileExists($this->dump . '/App/Modules/Blog/Config/module.php');
        $this->assertNotEmpty($data['paths']);
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
        $created = glob($this->dump . '/*_create_profiles.php');
        $this->assertNotEmpty($created);
        $this->assertSame([$created[0]], $data['paths']);
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
        $this->assertFileExists($this->dump . '/App/Models/Profile.php');
        $this->assertFileExists($this->dump . '/App/Modules/admin/Controller/ProfileController.php');
        $this->assertNotEmpty($data['paths']);
    }
}