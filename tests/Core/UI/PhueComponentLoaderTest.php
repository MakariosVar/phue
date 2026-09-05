<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\PhueComponent;
use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Contract tests for the convention-based .phue component loader and its
 * mtime-keyed parse cache.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhueComponentLoaderTest extends TestCase
{
    private const DIR = __DIR__ . '/ComponentsFixture';

    private string $componentsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->componentsDir = self::DIR . '/views';
        if (is_dir($this->componentsDir)) {
            $this->rmTree($this->componentsDir);
        }
        mkdir($this->componentsDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->componentsDir)) {
            $this->rmTree($this->componentsDir);
        }

        parent::tearDown();
    }

    private function rmTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->rmTree($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    private function writeComponent(string $name, string $template, array $state = [], string $script = ''): void
    {
        $sections = [
            "<php>return ['title' => '{$name}', 'state' => " . var_export($state, true) . "];</php>",
            "<template>{$template}</template>",
        ];
        if ($script !== '') {
            $sections[] = "<script>{$script}</script>";
        }

        file_put_contents($this->componentsDir . '/' . $name . '.phue', implode("\n", $sections));
    }

    public function testLoadsComponentByConventionName(): void
    {
        $this->writeComponent('Products', '<div>Products</div>', ['products' => []]);
        $loader = new PhueComponentLoader($this->componentsDir);

        $component = $loader->load('Products');

        $this->assertInstanceOf(PhueComponent::class, $component);
        $this->assertSame('Products', $component->title);
        $this->assertStringContainsString('<div>Products</div>', $component->view->getTemplate());
    }

    public function testLoadThrowsForUnknownComponent(): void
    {
        $loader = new PhueComponentLoader($this->componentsDir);

        $this->expectException(RuntimeException::class);

        $loader->load('DoesNotExist');
    }

    #[DataProvider('disallowedNamesProvider')]
    public function testLoadRejectsPathTraversalNames(string $name): void
    {
        $loader = new PhueComponentLoader($this->componentsDir);

        $this->expectException(RuntimeException::class);

        $loader->load($name);
    }

    public static function disallowedNamesProvider(): iterable
    {
        yield 'parent traversal' => ['../secret'];
        yield 'nested path' => ['admin/list'];
        yield 'backslash' => ['a\\b'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'dotfile' => ['.hidden'];
        yield 'empty' => [''];
        yield 'with space' => ['my view'];
    }

    public function testLoadCachesIdenticalInstanceAcrossCalls(): void
    {
        $this->writeComponent('Cache', '<div>c</div>');
        $loader = new PhueComponentLoader($this->componentsDir);

        $first = $loader->load('Cache');
        $second = $loader->load('Cache');

        $this->assertSame($first, $second);
    }

    public function testCacheRefreshesAfterFileModification(): void
    {
        $this->writeComponent('Live', '<div>v1</div>');
        $loader = new PhueComponentLoader($this->componentsDir);

        $first = $loader->load('Live');
        $this->assertStringContainsString('v1', $first->view->getTemplate());

        touch($this->componentsDir . '/Live.phue', time() + 1);
        $this->writeComponent('Live', '<div>v2</div>', ['k' => 'second'], '');
        touch($this->componentsDir . '/Live.phue', time() + 2);

        $second = $loader->load('Live');

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('v2', $second->view->getTemplate());
    }

    public function testCacheRefreshesAfterFileDeletionAndRecreation(): void
    {
        $this->writeComponent('Respawn', '<div>first</div>');
        $loader = new PhueComponentLoader($this->componentsDir);
        $loader->load('Respawn');

        unlink($this->componentsDir . '/Respawn.phue');
        $this->writeComponent('Respawn', '<div>reborn</div>');

        $reloaded = $loader->load('Respawn');

        $this->assertStringContainsString('reborn', $reloaded->view->getTemplate());
    }

    public function testRenderByNameProducesSamePayloadAsRenderFile(): void
    {
        $this->writeComponent('Dup', '<div class="twin">X</div>', ['n' => 5], "console.log('x');");

        $sharedRegistry = new AssetRegistry();
        $renderer = new ComponentRenderer(
            new HtmlShellRenderer($sharedRegistry),
            $sharedRegistry,
            new PhueComponentLoader($this->componentsDir)
        );

        $byName = $renderer->render('Dup')->getContent();
        $byFile = $renderer->renderFile($this->componentsDir . '/Dup.phue')->getContent();

        $this->assertSame($byFile, $byName);
    }
}