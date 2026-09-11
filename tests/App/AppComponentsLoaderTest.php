<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * Contract guards for the App Components loader (/App/Frontend/Components).
 *
 * A dedicated loader rooted at /App/Frontend/Components must be wired into the
 * ComponentLoaderRegistry so tag-referenced App components such as <Card/>
 * resolve to their own .phue files — without polluting Pages/ and without
 * falling through to the Phui core library.
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
    }

    public function testComponentsLoaderMakesCardDiscoverable(): void
    {
        $registry = $this->app->getContainer()->get(ComponentLoaderRegistry::class);

        $this->assertTrue(
            $registry->hasComponent('Card'),
            'Card must be resolvable via the App Components loader.'
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

    public function testHomePageAutoImportsAppCard(): void
    {
        $page = $this->app->handle(new Request('GET', '/'))->getContent();

        preg_match('/src="(\/assets\/phue\/Pages\/Home\.[a-f0-9]{8}\.phue\.js)"/', $page, $module);
        $this->assertArrayHasKey(1, $module);

        $moduleScript = $this->app->handle(new Request('GET', $module[1]))->getContent();

        // <Card/> on the Home template is auto-discovered and imported with its
        // template export, exactly like Phui core children.
        $this->assertStringContainsString(
            'import __phueRaw_Card, { __phueTemplate as __phueTpl_Card }',
            $moduleScript
        );
        $this->assertStringContainsString(
            'const Card = { ...(__phueRaw_Card || {}), template: __phueTpl_Card };',
            $moduleScript
        );

        // Minting == serving: the URL the page imports MUST be servable.
        // Any imported child (even a depth-2 parent) must resolve to the full
        // module — otherwise the client 404s on the hydration module.
        $this->assertImportedChildServable($moduleScript, 'Card');
    }

    public function testCardTransitiveChildIsServable(): void
    {
        $registry = $this->app->getContainer()->get(ComponentLoaderRegistry::class);

        // Card now references <ph-button/> itself: its full module must import
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