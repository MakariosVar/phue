<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Controller;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Modules\Schii\Config\SchiiConfig;
use Phue\Modules\Schii\Controller\PhiiController;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P1 — `apply` is the arbitrary file-write surface: a crafted plan must never
 * escape the configured output root, regardless of `..` traversal, absolute
 * paths, symlinks, or sibling directories sharing a name prefix with the root.
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
        $this->rmTree($this->dump . '-evil');
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

    private function applyPath(string $path): \Phue\Core\Response
    {
        $request = new Request('POST', '/phii/apply', [], [
            'plan' => [['path' => $path, 'content' => 'pwned']],
        ]);
        return $this->controller()->apply($request);
    }

    public function testApplyRejectsDotDotTraversal(): void
    {
        $parent = dirname($this->dump);
        $target = $parent . '/pwned_up_' . mt_rand(). '.txt';

        $response = $this->applyPath("{$this->dump}/../../" . basename($target));

        $this->assertSame(403, $response->getStatusCode(), '`..` climbing out of the root must be blocked');
        $this->assertFileDoesNotExist($target, 'no file may land outside the root');
    }

    public function testApplyRejectsAbsolutePathOutsideRoot(): void
    {
        $outsideDir = sys_get_temp_dir() . '/phue_phii_outside_' . uniqid();
        @mkdir($outsideDir, 0755, true);

        try {
            $target = $outsideDir . '/pwned_abs.txt';
            $response = $this->applyPath($target);

            $this->assertSame(403, $response->getStatusCode(), 'absolute paths outside the root must be blocked');
            $this->assertFileDoesNotExist($target);
        } finally {
            $this->rmTree($outsideDir);
        }
    }

    public function testApplyRejectsSymlinkEscape(): void
    {
        $outsideDir = sys_get_temp_dir() . '/phue_phii_linkdest_' . uniqid();
        @mkdir($outsideDir, 0755, true);

        try {
            $link = $this->dump . '/escape';
            symlink($outsideDir, $link);

            $target = $outsideDir . '/pwned_link.txt';
            $response = $this->applyPath($link . '/pwned_link.txt');

            $this->assertSame(403, $response->getStatusCode(), 'writing through a symlink to outside must be blocked');
            $this->assertFileDoesNotExist($target);
        } finally {
            $this->rmTree($outsideDir);
        }
    }

    public function testApplyBlocksSiblingDirSharingRootNamePrefix(): void
    {
        $sibling = $this->dump . '-evil';
        @mkdir($sibling, 0755, true);

        $response = $this->applyPath($this->dump . '/../' . basename($sibling) . '/pwned_prefix.txt');

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'a sibling whose name begins with the root must not be reachable'
        );
        $this->assertFileDoesNotExist($sibling . '/pwned_prefix.txt', 'escape into a sibling tree must be blocked');
    }
}