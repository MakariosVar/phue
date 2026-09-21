<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Controller;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Schii\Config\SchiiConfig;
use Phue\Modules\Schii\Controller\PhiiController;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Schii\Stubs\Profile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P1 — `apply` writes server-derived artifacts and no longer accepts any
 * client-supplied `content` or raw plan paths, so arbitrary path traversal is
 * structurally impossible (the written paths are generated inside the root).
 * The surviving containment surfaces are still defended:
 *   - a server-derived path that resolves through a symlink out of the root
 *     must be rejected;
 *   - the optional client `paths` selector is matched against the already
 *     contained plan and can never force a write outside the root.
 *
 * @package Phue\Tests\Schii\Controller
 */
#[Group('unit')]
final class PhiiContainmentTest extends TestCase
{
    private string $dump;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dump = sys_get_temp_dir() . '/phue_phii_contain_' . uniqid();
        @mkdir($this->dump, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->dump);
        parent::tearDown();
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir) && !is_link($dir)) {
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
        is_link($dir) ? unlink($dir) : rmdir($dir);
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

    private function applyModel(array $body = []): Response
    {
        $request = new Request('POST', '/phii/apply', [], array_merge([
            'generator' => 'model',
            'entity' => Profile::class,
            'module' => 'admin',
            'options' => ['type' => 'active-record'],
        ], $body));

        return $this->controller()->apply($request);
    }

    public function testApplyRejectsSymlinkEscape(): void
    {
        $outsideDir = sys_get_temp_dir() . '/phue_phii_linkdest_' . uniqid();
        @mkdir($outsideDir, 0755, true);

        try {
            mkdir($this->dump . '/App', 0777, true);
            symlink($outsideDir, $this->dump . '/App/Models');

            $target = $outsideDir . '/Profile.php';
            $response = $this->applyModel();

            $this->assertSame(403, $response->getStatusCode(), 'writing through a symlink to outside must be blocked');
            $this->assertFileDoesNotExist($target, 'no file may land outside the root through a symlink');
        } finally {
            $this->rmTree($outsideDir);
        }
    }

    public function testApplyBlocksSiblingDirSharingRootNamePrefix(): void
    {
        // A hostile symlink whose target shares the root's name prefix must not
        // be treated as being inside the real output root.
        $sibling = $this->dump . '-evil';
        @mkdir($sibling, 0755, true);

        try {
            mkdir($this->dump . '/App', 0777, true);
            symlink($sibling, $this->dump . '/App/Models');

            $response = $this->applyModel();

            $this->assertSame(
                403,
                $response->getStatusCode(),
                'a symlink resolving into a sibling tree must not be considered contained'
            );
            $this->assertFileDoesNotExist($sibling . '/Profile.php', 'escape into a sibling tree must be blocked');
        } finally {
            $this->rmTree($sibling);
        }
    }

    public function testApplyPathsSelectorCannotForceWritesOutsideRoot(): void
    {
        $outsideDir = sys_get_temp_dir() . '/phue_phii_outside_' . uniqid();
        @mkdir($outsideDir, 0755, true);

        try {
            $target = $outsideDir . '/pwned.txt';
            $response = $this->applyModel([
                'paths' => [
                    $target,
                    $this->dump . '/../phue_evil/pwned.txt',
                ],
                'backup' => false,
            ]);

            // The selector only ever matches already-contained plan paths; the
            // foreign strings select nothing, so nothing outside may be written.
            $this->assertSame(200, $response->getStatusCode());
            $data = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('written', $data);
            $this->assertSame(0, $data['written'], 'client path selection cannot create files outside the root');
            $this->assertFileDoesNotExist($target);
            $this->assertFileDoesNotExist(sys_get_temp_dir() . '/phue_evil/pwned.txt');
        } finally {
            $this->rmTree($outsideDir);
        }
    }
}