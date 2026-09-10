<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * Regression stub for the removed Products demo route.
 *
 * ProductsController / Pages/Products were dropped from the demo app,
 * so GET /products must fail gracefully with a 404 instead of a crashed
 * routing pass or a half-rendered page.
 *
 * @package Phue\Tests\App
 */
final class ProductsControllerTest extends TestCase
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

    public function testRemovedProductsRouteReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/products'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Not Found', $response->getContent());
    }
}