<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Console;

use Phue\Core\Application;
use Phue\Core\Routing\RouteMatch;
use Phue\Core\Routing\Router;
use Phue\Core\Routing\Exceptions\MethodNotAllowedException;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Schii\Config\SchiiConfig;
use Phue\Schii\Controller\PhiiController;
use Phue\Schii\Endpoint\SchemaInspectorEndpoint;
use Phue\Schii\Endpoint\SchemaSyncEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * Phii panel wiring contracts:
 *  - the module registers the /phii (panel) and /phii/generate (action) routes,
 *  - entity-aware explorer routes exist for structure/sync,
 *  - SchiiConfig round-trips the Phii-specific keys,
 *  - the module owns a loadable 'Phii' component in its Frontend.
 *
 * @package Phue\Tests\Schii\Console
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class PhiiRoutingTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpBase = sys_get_temp_dir() . '/phue_phii_route_' . uniqid();
        mkdir($this->tmpBase . '/config', 0777, true);

        file_put_contents($this->tmpBase . '/config/app.php', <<<'PHP'
<?php
return [
    'debug' => true,
    'modules' => ['\\Phue\\Schii\\SchiiModule'],
];
PHP);

        file_put_contents($this->tmpBase . '/config/schii.php', <<<'PHP'
<?php
return [
    'apiPrefix' => '/api/schii',
    'entityNamespace' => 'Phue\\Tests\\Schii\\Stubs',
    'phiiPrefix' => '/phii',
    'phiiNamespace' => 'Phue\\Phii',
    'exportDir' => 'storage/generated',
    'requireAuth' => false,
];
PHP);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpBase)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpBase, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink()
                    ? rmdir($item->getPathname())
                    : unlink($item->getPathname());
            }
            rmdir($this->tmpBase);
        }

        parent::tearDown();
    }

    public function testModuleRegistersPhiiRoutes(): void
    {
        $app = new Application($this->tmpBase);
        $router = $app->getContainer()->get(Router::class);

        $panel = $router->dispatch('GET', '/phii');
        $this->assertInstanceOf(RouteMatch::class, $panel);
        $this->assertSame([PhiiController::class, 'index'], $panel->getHandler());

        $generate = $router->dispatch('POST', '/phii/generate');
        $this->assertInstanceOf(RouteMatch::class, $generate);
        $this->assertSame([PhiiController::class, 'generate'], $generate->getHandler());
    }

    public function testModuleRegistersExplorerRoutes(): void
    {
        $app = new Application($this->tmpBase);
        $router = $app->getContainer()->get(Router::class);

        $structure = $router->dispatch('GET', '/api/schii/schema/profiles/structure');
        $this->assertInstanceOf(RouteMatch::class, $structure);
        $this->assertSame([SchemaInspectorEndpoint::class, 'structure'], $structure->getHandler());

        $sync = $router->dispatch('POST', '/api/schii/schema/profiles/sync');
        $this->assertInstanceOf(RouteMatch::class, $sync);
        $this->assertSame([SchemaSyncEndpoint::class, 'sync'], $sync->getHandler());
    }

    public function testWrongVerbOnExistingRouteRejected(): void
    {
        $app = new Application($this->tmpBase);
        $router = $app->getContainer()->get(Router::class);

        $this->expectException(MethodNotAllowedException::class);
        $router->dispatch('DELETE', '/phii/generate');
    }

    public function testUnknownPhiiPathNotFound(): void
    {
        $app = new Application($this->tmpBase);
        $router = $app->getContainer()->get(Router::class);

        $this->expectException(RouteNotFoundException::class);
        $router->dispatch('GET', '/phii/not-a-page');
    }

    public function testSchiiConfigRoundTripsPhiiKeys(): void
    {
        $config = SchiiConfig::fromArray([
            'apiPrefix' => '/api/schii',
            'entityNamespace' => 'App\\Models',
            'phiiPrefix' => '/phii',
            'phiiNamespace' => 'Phue\\Phii',
            'exportDir' => 'storage/generated',
            'requireAuth' => true,
            'middleware' => ['auth'],
        ]);

        $this->assertSame('/phii', $config->phiiPrefix);
        $this->assertSame('Phue\\Phii', $config->phiiNamespace);
        $this->assertSame('storage/generated', $config->exportDir);
    }

    public function testSchiiConfigPhiiDefaultsAreSane(): void
    {
        $config = SchiiConfig::fromArray([]);

        $this->assertSame('/phii', $config->phiiPrefix);
        $this->assertSame('App\\Models', $config->phiiNamespace);
        $this->assertSame('storage/generated', $config->exportDir);
    }

    public function testModuleOwnsPhiiComponentFileWithExactCasing(): void
    {
        $frontend = dirname(__DIR__, 3) . '/src/Schii/Frontend';
        $this->assertFileExists("{$frontend}/Phii.phue");
        $this->assertFileDoesNotExist("{$frontend}/phii.phue", 'Component file must be named Phii.phue (case-sensitive).');
    }
}