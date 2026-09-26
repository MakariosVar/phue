<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that a real app page (Docs.phue with styles) actually
 * emits and serves its declared stylesheet through the Application.
 *
 * Mirrors public/index.php registration order.
 *
 * @package Phue\Tests\App
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class AppStylesheetE2ETest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application('/app');
        $this->app->getRouter()->scanControllers('/app/App/Controllers', 'App\\Controllers');

        // Mirror public/index.php source registration order.
        $registry = $this->app->getContainer()->get(AssetRegistry::class);
        $registry->registerSource('/assets', '/app/App/Frontend');
        $registry->registerSource('/assets/app', '/app/App/Assets');
        $registry->registerSource('/assets/vendor', '/app/storage/assets/vendor');
    }

    public function testDocsPageEmitsStylesLink(): void
    {
        $response = $this->app->handle(new Request('GET', '/docs'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            '<link rel="stylesheet" href="/assets/app/styles/main.css">',
            $response->getContent()
        );
    }

    public function testDocsDeclaredStylesheetIsServed(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/app/styles/main.css'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('custom css', $response->getContent());
    }
}
