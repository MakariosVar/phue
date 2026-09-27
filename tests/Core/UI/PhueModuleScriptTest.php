<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\View;
use Phue\Tests\Core\Stubs\AssertsPhueJsModuleContract;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * Contract tests for extracted per-component ESM module scripts
 * (option 1b): content-hash URLs served statelessly, with the direct
 * render(title, view) path keeping inline-module behaviour (BC).
 *
 * The extracted module carries a precompiled render function: it must expose
 * `__phueRender` (not the raw JSON template), wire it into the app factory
 * proxy, remain valid ESM, and be served through the same minting == serving
 * hashing it was built with.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhueModuleScriptTest extends TestCase
{
    private const DIR = __DIR__ . '/ComponentsFixture';

    private string $componentsDir;
    private AssetRegistry $registry;
    private PhueComponentLoader $loader;
    private ComponentRenderer $renderer;

    private const SCRIPT = <<<'JS'
import { createApp, ref } from '/assets/vendor/vue3/vue.esm-browser.js';
createApp({ setup() { return { products: ref(window.__INITIAL_STATE__.products) }; } }).mount('#app');
JS;

    protected function setUp(): void
    {
        parent::setUp();

        $this->componentsDir = self::DIR . '/views';
        if (is_dir($this->componentsDir)) {
            $this->rmTree($this->componentsDir);
        }
        mkdir($this->componentsDir, 0777, true);

        $this->registry = new AssetRegistry();
        $this->loader = TestComponentLoader::make($this->componentsDir);
        $this->renderer = new ComponentRenderer(
            new HtmlShellRenderer($this->registry),
            $this->registry,
            $this->loader
        );

        $this->writeScripted();
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

    private function writeScripted(string $script = self::SCRIPT): string
    {
        $name = 'Scripted';
        $content = <<<PHUE
<php>return ['title' => 'Scripted Page', 'state' => ['products' => []]];</php>
<template><div id="catalog">Products</div></template>
<script>
{$script}
</script>
PHUE;
        file_put_contents($this->componentsDir . '/' . $name . '.phue', $content);

        return $name;
    }

    public function testRenderByNameEmitsContentHashModuleUrlInsteadOfInline(): void
    {
        $content = $this->renderer->render('Scripted')->getContent();

        $this->assertMatchesRegularExpression(
            '/<script type="module" src="\/assets\/phue\/Scripted\.[a-f0-9]{8}\.phue\.js"><\/script>/',
            $content
        );
        $this->assertStringNotContainsString('<script type="module">', $content);
        $this->assertStringNotContainsString("createApp", $content);
    }

    public function testModuleHashIsDerivedFromModuleContent(): void
    {
        $expectedModule = TestComponentLoader::moduleFor('<div id="catalog">Products</div>', trim(self::SCRIPT));
        $expectedHash = substr(sha1($expectedModule), 0, 8);

        $content = $this->renderer->render('Scripted')->getContent();

        $this->assertStringContainsString("/assets/phue/Scripted.{$expectedHash}.phue.js", $content);
    }

    public function testScriptForReturnsContentWhenHashMatches(): void
    {
        $script = $this->loader->scriptFor('Scripted', substr(hash('sha1', trim(self::SCRIPT)), 0, 8));

        $this->assertSame(trim(self::SCRIPT), $script);
    }

    public function testScriptForNeverEvaluatesPhpBlock(): void
    {
        $script = "console.log('pure script');";
        file_put_contents($this->componentsDir . '/NoEval.phue', <<<PHUE
<php>echo 'PHP-LEAK'; return ['title' => 'NoEval', 'state' => []];</php>
<template><div>NoEval</div></template>
<script>{$script}</script>
PHUE);

        ob_start();
        $served = $this->loader->scriptFor('NoEval', substr(hash('sha1', trim($script)), 0, 8));
        $leaked = ob_get_clean();

        $this->assertSame($script, $served);
        $this->assertSame('', $leaked, 'scriptFor must not evaluate the <php> block (no output side effects)');
    }

    public function testScriptForReturnsNullOnHashMismatch(): void
    {
        $this->assertNull($this->loader->scriptFor('Scripted', '0badc0de'));
    }

    public function testScriptForRejectsTraversalAndMalformedNames(): void
    {
        $this->assertNull($this->loader->scriptFor('../secret', 'abcdef12'));
        $this->assertNull($this->loader->scriptFor('a/b', 'abcdef12'));
        $this->assertNull($this->loader->scriptFor('Secret;rm', 'abcdef12'));
    }

    public function testModuleUrlChangesWhenScriptContentChanges(): void
    {
        $first = $this->renderer->render('Scripted')->getContent();
        $this->writeScripted("console.log('new logic');" . "\n" . self::SCRIPT);

        // Create new loader to clear in-memory cache (stat cache cleared by clearstatcache in loader)
        $this->loader = TestComponentLoader::make($this->componentsDir);
        $this->registry = new AssetRegistry();
        $this->renderer = new ComponentRenderer(
            new HtmlShellRenderer($this->registry),
            $this->registry,
            $this->loader
        );
        $second = $this->renderer->render('Scripted')->getContent();

        $this->assertNotEquals($first, $second);

        preg_match('/Scripted\.([a-f0-9]{8})\.phue\.js/', $first, $m1);
        preg_match('/Scripted\.([a-f0-9]{8})\.phue\.js/', $second, $m2);
        $this->assertNotSame($m1[1], $m2[1]);

        $this->assertNull($this->loader->scriptFor('Scripted', $m1[1]));
    }

    public function testModuleForReturnsCombinedTemplateAndScript(): void
    {
        $expectedModule = TestComponentLoader::moduleFor('<div id="catalog">Products</div>', trim(self::SCRIPT));
        $hash = substr(sha1($expectedModule), 0, 8);

        $module = $this->loader->moduleFor('Scripted', $hash);

        $this->assertSame($expectedModule, $module);
        $this->assertStringContainsString('export const __phueRender', $module);
        $this->assertStringContainsString('render: __phueRender', $module);
        $this->assertStringContainsString('__phueOrigCreateApp', $module);
        $this->assertStringContainsString('.mount(\'#app\')', $module);

        AssertsPhueJsModuleContract::assertEmittedModuleIsLoadableJs($this, $module, 'Scripted page module');
    }

    public function testModuleForReturnsNullOnHashMismatch(): void
    {
        $this->assertNull($this->loader->moduleFor('Scripted', '0badc0de'));
    }

    public function testModuleForReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->loader->moduleFor('NoSuchComponent', 'deadbeef'));
    }

    public function testModuleForRejectsTraversalAndMalformedNames(): void
    {
        $this->assertNull($this->loader->moduleFor('../secret', 'abcdef12'));
        $this->assertNull($this->loader->moduleFor('a/b', 'abcdef12'));
        $this->assertNull($this->loader->moduleFor('Secret;rm', 'abcdef12'));
    }

    public function testModuleForNeverEvaluatesPhpBlock(): void
    {
        $script = "export default {};";
        file_put_contents($this->componentsDir . '/NoEval.phue', <<<PHUE
<php>echo 'PHP-LEAK'; return ['title' => 'NoEval', 'state' => []];</php>
<template><div>NoEval</div></template>
<script>{$script}</script>
PHUE);

        $expectedModule = TestComponentLoader::moduleFor('<div>NoEval</div>', $script);
        $hash = substr(sha1($expectedModule), 0, 8);

        ob_start();
        $served = $this->loader->moduleFor('NoEval', $hash);
        $leaked = ob_get_clean();

        $this->assertSame($expectedModule, $served);
        $this->assertSame('', $leaked, 'moduleFor must not evaluate the <php> block (no output side effects)');
    }

    public function testModuleForNeverCarriesStatePayload(): void
    {
        file_put_contents($this->componentsDir . '/Scripted.phue', <<<'PHUE'
<php>return ['title' => 'S', 'state' => ['flag' => 'SECRET-1337']];</php>
<template><div>StateFree</div></template>
<script>
export default { name: 'StateFree' };
</script>
PHUE);

        $expectedModule = TestComponentLoader::moduleFor('<div>StateFree</div>', "export default { name: 'StateFree' };");
        $hash = substr(sha1($expectedModule), 0, 8);

        $module = $this->loader->moduleFor('Scripted', $hash);

        $this->assertIsString($module);
        $this->assertStringNotContainsString('SECRET-1337', $module);
    }

    public function testModuleHashChangesWhenTemplateChanges(): void
    {
        $firstHash = $this->loader->moduleHash('Scripted');
        $pageScript = self::SCRIPT;

        $name = 'Scripted';
        file_put_contents($this->componentsDir . '/' . $name . '.phue', <<<PHUE
<php>return ['title' => 'Scripted Page', 'state' => ['products' => []]];</php>
<template><div id="catalog" data-changed>Products DELTA</div></template>
<script>
{$pageScript}
</script>
PHUE);

        $this->assertIsString($firstHash);
        $this->assertNotSame($firstHash, $this->loader->moduleHash('Scripted'));
    }

    public function testDirectViewRenderKeepsInlineModuleScript(): void
    {
        $view = new View('<div>Direct</div>', ['x' => 1], [], "console.log('direct');");
        $content = $this->renderer->render('Direct Title', $view)->getContent();

        $this->assertStringContainsString('<script type="module">', $content);
        $this->assertStringContainsString("console.log('direct');", $content);
        $this->assertStringNotContainsString('/assets/phue/', $content);
    }

    public function testExtractedModuleNeverCarriesStatePayload(): void
    {
        file_put_contents($this->componentsDir . '/Scripted.phue', <<<'PHUE'
<php>return ['title' => 'S', 'state' => ['flag' => 'SECRET-1337']];</php>
<template><div>x</div></template>
<script>
import { createApp } from '/assets/vendor/vue3/vue.esm-browser.js';
createApp({ setup() { return { flag: 'placeholder' }; } }).mount('#app');
</script>
PHUE);

        $script = $this->loader->scriptFor('Scripted', substr(hash('sha1', trim(<<<'JS'
import { createApp } from '/assets/vendor/vue3/vue.esm-browser.js';
createApp({ setup() { return { flag: 'placeholder' }; } }).mount('#app');
JS
        )), 0, 8));

        $this->assertIsString($script);
        $this->assertStringNotContainsString('SECRET-1337', $script);
    }
}