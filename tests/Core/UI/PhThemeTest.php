<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueTemplateParser;
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

    private function template(): string
    {
        return (string) ((new PhueTemplateParser())->parseStructure(
            self::PHUI_DIR . '/PhTheme.phue'
        )['template'] ?? '');
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

    /**
     * The glyph names the theme the button switches TO, matching the aria-label.
     *
     * It is tempting to "fix" this to mean the current theme while swapping the
     * emoji for <ph-icon>; that is the one edit that makes the control lie,
     * because the label keeps saying "Switch to light mode" next to a moon.
     */
    public function testTheGlyphNamesTheThemeTheButtonSwitchesTo(): void
    {
        $template = $this->template();

        // Two sibling <template v-…> branches, one per current theme. Each match is
        // the whole element, opening tag included: the theme test lives in the v-if
        // attribute, not in the body.
        preg_match_all('/<template\s+v-(?:if|else)[^>]*>.*?<\/template>/s', $template, $branches);
        $this->assertCount(2, $branches[0], 'the icon must branch on the current theme');

        [$dark, $light] = $branches[0];

        $this->assertStringContainsString(
            "currentTheme === 'dark'",
            $dark,
            'the first branch is the dark-theme one',
        );
        $this->assertStringContainsString('light-icon', $dark, 'while dark, the light-target slot must render');
        $this->assertStringContainsString('lightIconName', $dark);
        $this->assertStringNotContainsString('darkIconName', $dark, 'the dark branch must not show the current theme');

        $this->assertStringContainsString('dark-icon', $light, 'while light, the dark-target slot must render');
        $this->assertStringContainsString('darkIconName', $light);
        $this->assertStringNotContainsString('lightIconName', $light);
    }

    public function testTheIconIsAlwaysDecorativeSoTheLabelStaysTheAccessibleName(): void
    {
        $template = $this->template();

        // PhIcon sets role="img" + aria-label when given a `label`, which would
        // duplicate the button's own name inside the button.
        $this->assertStringNotContainsString(
            'label=',
            str_replace('aria-label=', '', $template),
            'the icon must never receive a label: PhIcon would turn it into role="img"',
        );
        $this->assertStringContainsString(':aria-label="resolvedAriaLabel"', $template);
    }

    public function testIconPropsAreDeclared(): void
    {
        $script = (string) ((new PhueTemplateParser())->parseStructure(
            self::PHUI_DIR . '/PhTheme.phue'
        )['script'] ?? '');


        foreach (['lightIcon', 'darkIcon', 'iconSize'] as $prop) {
            $this->assertStringContainsString(
                $prop . ':',
                $script,
                "PhTheme must declare the {$prop} prop",
            );
        }
    }

    public function testModuleBuildsForSsrWithSsrRenderFunction(): void
    {
        $ssrModule = $this->loader->buildForSsr('PhTheme');

        $this->assertIsString($ssrModule, 'buildForSsr must return string for PhTheme');
        $this->assertStringContainsString('export const __phueSsrRender', $ssrModule);
        $this->assertStringContainsString("name: 'PhTheme'", $ssrModule);
        $this->assertStringNotContainsString('<?', $ssrModule, 'the SSR module must contain no PHP markup');

        // The glyphs are resolved by name, so the SSR module has to carry the
        // theme comparison itself — not a baked-in emoji.
        $this->assertStringContainsString(
            '_resolveComponent("ph-icon")',
            $ssrModule,
            'the SSR module must resolve <ph-icon>, which is what pulls PhIcon into the '
            . 'renderer\'s component map for this page',
        );
        $this->assertStringNotContainsString('☀️', $ssrModule);
        $this->assertStringNotContainsString('🌙', $ssrModule);
    }

    public function testSsrRenderOutputViaSidecar(): void
    {
        $socketPath = '/tmp/phue-ssr.sock';
        if (!file_exists($socketPath)) {
            $this->markTestSkipped('Bun SSR socket is not active.');
        }

        $ssrClient = new \Phue\Core\Ssr\UnixSsrClient($socketPath);
        $ssrEngine = new \Phue\Core\Ssr\BunSsrEngine($ssrClient);

        $html = $this->ssr('<ph-theme aria-label="Toggle theme"></ph-theme>');

        $this->assertNotNull($html);
        $this->assertStringContainsString('<button type="button"', $html);
        $this->assertStringContainsString('aria-label="Toggle theme"', $html);
        $this->assertStringContainsString('bi-moon', $html, 'the dark-theme target glyph must render as a Bootstrap icon');
    }

    public function testSsrRenderOutputViaSidecarUsesTheConfiguredIcons(): void
    {
        $html = $this->ssr('<ph-theme light-icon="sun-fill" dark-icon="circle-half"></ph-theme>');

        $this->assertNotNull($html);
        $this->assertStringContainsString('bi-circle-half', $html);
        $this->assertStringNotContainsString('bi-moon', $html);
    }

    public function testAnEmptyIconNameRendersNoIconAtAll(): void
    {
        // The documented way to get an icon-less toggle. `||` would quietly turn
        // `light-icon=""` back into the default glyph instead.
        $html = $this->ssr('<ph-theme dark-icon=""></ph-theme>');

        $this->assertNotNull($html);
        $this->assertStringNotContainsString('data-phui="icon"', $html);
        $this->assertStringContainsString('aria-label=', $html, 'the button keeps its accessible name without an icon');
    }

    /**
     * Renders through the real SSR sidecar, with the same component map the
     * renderer builds: the page plus every transitive dependency it discovered.
     * PhTheme reaches PhIcon, so a map without it resolves nothing and the
     * button renders empty.
     */
    private function ssr(string $template): ?string
    {
        $socketPath = '/tmp/phue-ssr.sock';
        if (!file_exists($socketPath)) {
            $this->markTestSkipped('Bun SSR socket is not active.');
        }

        $client = new \Phue\Core\Ssr\UnixSsrClient($socketPath);
        $engine = new \Phue\Core\Ssr\BunSsrEngine($client);

        $components = [];
        foreach (['PhTheme', 'PhIcon'] as $name) {
            $components[$name] = $this->loader->buildForSsr($name);
        }

        return $engine->render($template, [], ['components' => $components]);
    }
}
