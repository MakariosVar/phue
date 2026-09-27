<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * Contract tests for the PhTheme theme toggle UI component.
 *
 * Locks:
 *  - Resolves via PhueComponentLoader as both client module and SSR module.
 *  - Client module carries precompiled __phueRender function.
 *  - SSR module carries precompiled __phueSsrRender function.
 *  - Client/server hydration stability: initial currentTheme is 'light' to prevent hydration mismatches.
 *  - Props: storageKey, ariaLabel, themeConfig.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
final class PhThemeTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    private PhueComponentLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = TestComponentLoader::make(self::PHUI_DIR);
    }

    private function module(): string
    {
        $hash = $this->loader->moduleHash('PhTheme');
        $this->assertIsString($hash, 'moduleHash must resolve PhTheme');

        $module = $this->loader->moduleFor('PhTheme', $hash);
        $this->assertIsString($module, 'moduleFor must serve PhTheme for its own hash');

        return $module;
    }

    public function testModuleResolvesWithClientRenderFunctionAndScript(): void
    {
        $module = $this->module();

        $this->assertStringStartsWith('import ', $module);
        $this->assertStringContainsString('export const __phueRender', $module);
        $this->assertStringContainsString("name: 'PhTheme'", $module);
        $this->assertStringContainsString('currentTheme: \'light\'', $module);
        $this->assertStringContainsString('syncTheme', $module);
        $this->assertStringContainsString('toggleTheme', $module);
    }

    public function testModuleBuildsForSsrWithSsrRenderFunction(): void
    {
        $ssrModule = $this->loader->buildForSsr('PhTheme');

        $this->assertIsString($ssrModule, 'buildForSsr must return string for PhTheme');
        $this->assertStringContainsString('export const __phueSsrRender', $ssrModule);
        $this->assertStringContainsString('ssrRenderStyle', $ssrModule);
        $this->assertStringContainsString("name: 'PhTheme'", $ssrModule);
    }

    public function testSsrRenderOutputViaSidecar(): void
    {
        $socketPath = '/tmp/phue-ssr.sock';
        if (!file_exists($socketPath)) {
            $this->markTestSkipped('Bun SSR socket is not active.');
        }

        $ssrClient = new \Phue\Core\Ssr\UnixSsrClient($socketPath);
        $ssrEngine = new \Phue\Core\Ssr\BunSsrEngine($ssrClient);

        $source = $this->loader->buildForSsr('PhTheme');
        $html = $ssrEngine->render(
            '<ph-theme aria-label="Toggle theme"></ph-theme>',
            [],
            ['components' => ['PhTheme' => $source]]
        );

        $this->assertNotNull($html);
        $this->assertStringContainsString('<button type="button"', $html);
        $this->assertStringContainsString('aria-label="Toggle theme"', $html);
        $this->assertStringContainsString('🌙', $html);
    }
}
