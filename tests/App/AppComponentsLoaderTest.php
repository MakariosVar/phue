<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Request;
use Phue\Core\UI\PhueComponentLoader;
use Psr\SimpleCache\CacheInterface;
use PHPUnit\Framework\TestCase;

/**
 * Contract guards for a components-rooted loader wired into the
 * ComponentLoaderRegistry (stub-based, no dependency on demo-app components).
 *
 * A dedicated loader rooted at /tests/App/Stubs/Components must be wired into
 * the ComponentLoaderRegistry so tag-referenced stub components such as
 * <Card/> resolve to their own .phue files — without polluting the Pages
 * loader and without falling through to the Phui core library.
 *
 * @package Phue\Tests\App
 */
final class AppComponentsLoaderTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application('/app');
        $this->app->getRouter()->scanControllers('/app/App/Controllers', 'App\\Controllers');
        $registry = $this->app->getContainer()->get(AssetRegistry::class);
        $registry->registerSource('/assets', '/app/App/Frontend');
        $registry->registerSource('/assets/vendor', '/app/storage/assets/vendor');

        $container = $this->app->getContainer();
        $loaderRegistry = $container->get(ComponentLoaderRegistry::class);
        $cache = $container->get(CacheInterface::class);

        // Stub components loader rooted at /tests/App/Stubs/Components.
        $components = new PhueComponentLoader(
            __DIR__ . '/Stubs/Components',
            persistentCache: $cache,
            viewsCacheDir: '/app/storage/cache/views'
        );
        $components->setRegistry($loaderRegistry);
        $loaderRegistry->addLoader($components);

        // Stub pages loader rooted at /tests/App/Stubs/Pages.
        $pages = new PhueComponentLoader(
            __DIR__ . '/Stubs/Pages',
            persistentCache: $cache,
            viewsCacheDir: '/app/storage/cache/views'
        );
        $pages->setRegistry($loaderRegistry);
        $loaderRegistry->addLoader($pages);
    }

    public function testComponentsLoaderMakesCardDiscoverable(): void
    {
        $registry = $this->app->getContainer()->get(ComponentLoaderRegistry::class);

        $this->assertTrue(
            $registry->hasComponent('Card'),
            'Card must be resolvable via the stubbed components loader.'
        );
        $this->assertNotNull($registry->rawModuleHash('Card'));

        $url = $registry->moduleUrl('Card');
        $this->assertNotNull($url);
        $this->assertMatchesRegularExpression('/^\/assets\/phue\/Card\.[a-f0-9]{8}\.phue\.js$/', $url);
    }

    public function testComponentsLoaderServesCardModule(): void
    {
        $registry = $this->app->getContainer()->get(ComponentLoaderRegistry::class);
        $url = $registry->moduleUrl('Card');
        $this->assertNotNull($url);

        $response = $this->app->handle(new Request('GET', $url));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('export const __phueTemplate', $response->getContent());
        $this->assertStringContainsString('export default', $response->getContent());
        $this->assertStringContainsString('Hello there', $response->getContent());
    }

    public function testPageAutoImportsStubCard(): void
    {
        $registry = $this->app->getContainer()->get(ComponentLoaderRegistry::class);
        $url = $registry->moduleUrl('ShowcasePage');
        $this->assertNotNull($url);

        $moduleScript = $this->app->handle(new Request('GET', $url))->getContent();

        // <Card/> on the stub page template is auto-discovered and imported with
        // its template export, exactly like Phui core children.
        $this->assertStringContainsString(
            'import __phueRaw_Card, { __phueTemplate as __phueTpl_Card }',
            $moduleScript
        );
        $this->assertStringContainsString(
            'const Card = { ...(__phueRaw_Card || {}), template: __phueTpl_Card };',
            $moduleScript
        );

        // Minting == serving: the URL the page imports MUST be servable.
        $this->assertImportedChildServable($moduleScript, 'Card');
    }

    public function testCardTransitiveChildIsServable(): void
    {
        $registry = $this->app->getContainer()->get(ComponentLoaderRegistry::class);

        // Card references <ph-button/> itself: its full module must import
        // PhButton through the same minting == serving contract.
        $url = $registry->moduleUrl('Card');
        $this->assertNotNull($url);

        $cardModule = $this->app->handle(new Request('GET', $url))->getContent();
        $this->assertSame(200, $this->app->handle(new Request('GET', $url))->getStatusCode());
        $this->assertStringContainsString(
            'import __phueRaw_PhButton, { __phueTemplate as __phueTpl_PhButton }',
            $cardModule
        );
        $this->assertStringContainsString(
            'const PhButton = { ...(__phueRaw_PhButton || {}), template: __phueTpl_PhButton };',
            $cardModule
        );

        // Client-side depth-2 resolution: a script-based child (Card) must
        // declare its own children in the exported default options, so the
        // parent's wrapped const spreads them and <ph-button/> resolves on
        // the client — mirroring the template-only branch.
        $this->assertStringContainsString(
            'export default { components: { PhButton },',
            $cardModule
        );

        $this->assertImportedChildServable($cardModule, 'PhButton');
    }

    private function assertImportedChildServable(string $parentModule, string $child): void
    {
        preg_match_all(
            "~^import __phueRaw_{$child}, \{ __phueTemplate as __phueTpl_{$child} \} from '([^']+\.phue\.js)';~m",
            $parentModule,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $url = $m[1];
            $response = $this->app->handle(new Request('GET', $url));
            $this->assertSame(
                200,
                $response->getStatusCode(),
                "Imported URL {$url} of {$child} must be servable (minting == serving)."
            );
            $this->assertStringContainsString('export const __phueTemplate', $response->getContent());
            $this->assertStringContainsString('export default', $response->getContent());
        }
    }
}