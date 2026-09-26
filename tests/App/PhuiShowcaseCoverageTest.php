<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Config;
use Phue\Core\Request;
use Phue\Core\Ssr\UnixSsrClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PhuiShowcaseCoverageTest — guards the `/ui` showcase against component drift.
 *
 * The showcase page is the single manual-verification surface for the built-in
 * PhUI library. When a new component is added to `src/Core/UI/Phui/`, it must be
 * demonstrated on `/ui`; otherwise it silently loses its live demo and usage
 * snippet. This test renders the real page, resolves its minted client module
 * and asserts that every component in the P0 set is imported and served.
 *
 * Requires a live Bun SSR sidecar (the module build goes through the same
 * compiler bridge as the runtime); self-skips otherwise.
 *
 * @package Phue\Tests\App
 */
#[Group('ssr-rendered-pages')]
#[Group('integration')]
final class PhuiShowcaseCoverageTest extends TestCase
{
    /**
     * Components introduced in the first PhUI build-out phase. Every one of
     * them must be wired into the showcase.
     *
     * @var list<string>
     */
    private const SHOWCASED = [
        'PhIcon',
        'PhSpinner',
        'PhSwitch',
        'PhDropdown',
        'PhTooltip',
        'PhDrawer',
        'PhConfirmDialog',
        'PhSelect3',
        'PhFileInput',
        'PhDatePicker',
    ];

    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application('/app');
        $this->app->getRouter()->scanControllers('/app/App/Controllers', 'App\\Controllers');

        $registry = $this->app->getContainer()->get(AssetRegistry::class);
        $registry->registerSource('/assets', '/app/App/Frontend');
        $registry->registerSource('/assets/vendor', '/app/storage/assets/vendor');

        if (!$this->ssrClient()->isAvailable()) {
            self::markTestSkipped('Bun SSR sidecar is not reachable.');
        }
    }

    public function testUiShowcaseImportsAndServesEveryNewComponent(): void
    {
        $pageModuleUrl = $this->uiPageModuleUrl();
        $module = $this->app->handle(new Request('GET', $pageModuleUrl));

        $this->assertSame(200, $module->getStatusCode(), 'UI page module must be served.');
        $js = $module->getContent();
        $this->assertNotSame('', $js, 'UI page module must not be empty.');
        $this->assertStringNotContainsString('{{', $js, 'Client module must be compiled, not raw template.');

        foreach (self::SHOWCASED as $name) {
            $this->assertMatchesRegularExpression(
                '#/assets/phue/' . preg_quote($name, '#') . '\.[a-f0-9]{8}\.phue\.js#',
                $js,
                sprintf('<%s> must be imported by the /ui showcase.', $name)
            );
        }
    }

    public function testUiShowcaseComponentModulesAllResolve(): void
    {
        $module = $this->app->handle(new Request('GET', $this->uiPageModuleUrl()));
        preg_match_all('#/assets/phue/[A-Za-z0-9_\-]+\.[a-f0-9]{8}\.phue\.js#', $module->getContent(), $matches);

        $urls = array_values(array_unique($matches[0]));
        $this->assertNotEmpty($urls, 'UI page module must import at least one component.');

        foreach ($urls as $url) {
            $response = $this->app->handle(new Request('GET', $url));
            $this->assertSame(200, $response->getStatusCode(), "Component module {$url} must resolve.");
            $this->assertNotSame('', $response->getContent(), "Component module {$url} must not be empty.");
        }
    }

    private function uiPageModuleUrl(): string
    {
        $response = $this->app->handle(new Request('GET', '/phui'));
        $this->assertSame(200, $response->getStatusCode(), '/ui must render.');

        preg_match('#/assets/phue/Pages/PhUI\.[a-f0-9]{8}\.phue\.js#', $response->getContent(), $matches);

        $this->assertNotEmpty($matches, 'UI page must reference its minted client module.');

        return $matches[0];
    }

    private function ssrClient(): UnixSsrClient
    {
        $config = $this->app->getContainer()->get(Config::class);

        return new UnixSsrClient(
            socket: (string) $config->get('ssr.socket', "\0phue-ssr"),
        );
    }
}
