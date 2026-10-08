<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Console;

use Phue\Core\Application;
use Phue\Core\Routing\RouteMatch;
use Phue\Core\Routing\Router;
use Phue\Core\Routing\Exceptions\MethodNotAllowedException;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Modules\Schii\Config\SchiiConfig;
use Phue\Modules\Schii\Controller\PhiiController;
use Phue\Modules\Schii\Endpoint\AuditEndpoint;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Endpoint\SchemaInspectorEndpoint;
use Phue\Modules\Schii\Endpoint\SchemaSyncEndpoint;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

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
    use RestoresActiveRecordConnection;

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
    'modules' => ['\\Phue\\Modules\\Schii\\SchiiModule'],
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

        file_put_contents($this->tmpBase . '/config/database.php', <<<'PHP'
<?php
return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ],
    ],
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

        $diff = $router->dispatch('GET', '/api/schii/schema/profiles/diff');
        $this->assertInstanceOf(RouteMatch::class, $diff);
        $this->assertSame([SchemaSyncEndpoint::class, 'diff'], $diff->getHandler());
    }

    public function testModuleRegistersAdvancedCrudRoutes(): void
    {
        $app = new Application($this->tmpBase);
        $router = $app->getContainer()->get(Router::class);

        $bulk = $router->dispatch('POST', '/api/schii/profiles/bulk');
        $this->assertInstanceOf(RouteMatch::class, $bulk);
        $this->assertSame([ModelEndpoint::class, 'bulk'], $bulk->getHandler());
        $this->assertSame('profiles', $bulk->getParams()['entity'] ?? null);

        $export = $router->dispatch('GET', '/api/schii/profiles/export');
        $this->assertInstanceOf(RouteMatch::class, $export);
        $this->assertSame([ModelEndpoint::class, 'export'], $export->getHandler());
        $this->assertSame('profiles', $export->getParams()['entity'] ?? null);
        $this->assertArrayNotHasKey('id', $export->getParams(), 'Export must not be routed as {id}');

        $import = $router->dispatch('POST', '/api/schii/profiles/import');
        $this->assertInstanceOf(RouteMatch::class, $import);
        $this->assertSame([ModelEndpoint::class, 'import'], $import->getHandler());
        $this->assertSame('profiles', $import->getParams()['entity'] ?? null);
    }

    public function testModuleRegistersPhiiPreviewAndApplyRoutes(): void
    {
        $app = new Application($this->tmpBase);
        $router = $app->getContainer()->get(Router::class);

        $preview = $router->dispatch('POST', '/phii/preview');
        $this->assertInstanceOf(RouteMatch::class, $preview);
        $this->assertSame([PhiiController::class, 'preview'], $preview->getHandler());

        $apply = $router->dispatch('POST', '/phii/apply');
        $this->assertInstanceOf(RouteMatch::class, $apply);
        $this->assertSame([PhiiController::class, 'apply'], $apply->getHandler());
    }

    public function testAuditLiteralRouteWinsOverEntityParamRoute(): void
    {
        // requireAuth=false registers the plain AuditEndpoint; the literal /audit
        // route is declared before the generic /{entity} routes and must not be
        // captured as a CRUD listing for an entity literally named "audit".
        $app = new Application($this->tmpBase);
        $router = $app->getContainer()->get(Router::class);

        $audit = $router->dispatch('GET', '/api/schii/audit');
        $this->assertInstanceOf(RouteMatch::class, $audit);
        $this->assertSame([AuditEndpoint::class, 'index'], $audit->getHandler());

        $auditEntity = $router->dispatch('GET', '/api/schii/audit/profiles');
        $this->assertInstanceOf(RouteMatch::class, $auditEntity);
        $this->assertSame([AuditEndpoint::class, 'forEntity'], $auditEntity->getHandler());
    }

    public function testSchemaInspectorEndpointResolvesFromContainer(): void
    {
        $app = new Application($this->tmpBase);
        $container = $app->getContainer();

        // S1 regression: the DI binding must resolve SchemaInspectorEndpoint
        // with the correct constructor argument order (no TypeError/HTTP 500).
        $inspector = $container->get(SchemaInspectorEndpoint::class);

        $this->assertInstanceOf(SchemaInspectorEndpoint::class, $inspector);

        $router = $container->get(Router::class);
        $structure = $router->dispatch('GET', '/api/schii/schema/user/structure');
        $this->assertInstanceOf(RouteMatch::class, $structure);
        $this->assertSame([SchemaInspectorEndpoint::class, 'structure'], $structure->getHandler());
    }

    public function testSchemaLiteralRoutesWinOverEntityParamRoutes(): void
    {
        $app = new Application($this->tmpBase);
        $router = $app->getContainer()->get(Router::class);

        // /schema (no entity) and /schema/{entity} must stay dedicated to metadata
        $schema = $router->dispatch('GET', '/api/schii/schema');
        $this->assertInstanceOf(RouteMatch::class, $schema);
        $this->assertSame([ModelEndpoint::class, 'listSchemas'], $schema->getHandler());

        $schemaEntity = $router->dispatch('GET', '/api/schii/schema/user');
        $this->assertInstanceOf(RouteMatch::class, $schemaEntity);
        $this->assertSame([ModelEndpoint::class, 'schema'], $schemaEntity->getHandler());
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
        $frontend = dirname(__DIR__, 3) . '/src/Modules/Phii/Frontend';
        $this->assertFileExists("{$frontend}/Phii.phue");
        $this->assertFileDoesNotExist("{$frontend}/phii.phue", 'Component file must be named Phii.phue (case-sensitive).');
    }

    public function testPhiiViewsAreNotParkedInTheSchiiModuleFrontend(): void
    {
        $schiiFrontend = dirname(__DIR__, 3) . '/src/Modules/Schii/Frontend';

        foreach (['Phii.phue', 'PhiiPreviewPanel.phue'] as $leaked) {
            $this->assertFileDoesNotExist(
                "{$schiiFrontend}/{$leaked}",
                "{$leaked} is owned by the Phii module; keeping it under Schii would let the Schii loader serve Phii pages."
            );
        }
    }

    public function testPhiiLoaderServiceResolvesAgainstARealDirectory(): void
    {
        $app = new Application($this->tmpBase);
        $container = $app->getContainer();

        $this->assertTrue(
            $container->has('phii.loader'),
            'phii.renderer resolves phii.loader; a missing service turns /phii into a 500.'
        );

        try {
            $loader = $container->get('phii.loader');
        } catch (\Throwable $e) {
            $this->fail("phii.loader must construct against a valid componentsDir. Got: {$e->getMessage()}");
        }

        // A malformed componentsDir (e.g. __DIR__ . '../Phii/Frontend' with a
        // missing separator) must not be able to reach the router silently.
        $this->assertTrue(
            $loader->hasComponent('Phii'),
            'The registered phii.loader must actually own Phii.phue.'
        );
    }
}