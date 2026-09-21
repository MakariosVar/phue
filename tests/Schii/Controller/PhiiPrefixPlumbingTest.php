<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Generator;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Modules\Schii\Config\SchiiConfig;
use Phue\Modules\Schii\Controller\PhiiController;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P11 — custom `phiiPrefix`/`phiiNamespace` must flow from configuration into the
 * Phii panel state (route registration already honors them in SchiiModule).
 * Regression lock so the plumbing cannot silently regress.
 *
 * @package Phue\Tests\Schii\Controller
 */
#[Group('unit')]
final class PhiiPrefixPlumbingTest extends TestCase
{
    public function testCustomPhiiPrefixReachesPanelState(): void
    {
        $captured = null;

        $renderer = $this->createMock(\Phue\Core\UI\ComponentRenderer::class);
        $renderer->method('render')->willReturnCallback(
            function (string $name, $viewOrTitleOrParams = null, array $params = []) use (&$captured): \Phue\Core\Response {
                $captured = is_array($viewOrTitleOrParams) ? $viewOrTitleOrParams : $params;
                return new \Phue\Core\Response('ok');
            }
        );

        $config = SchiiConfig::fromArray([
            'apiPrefix' => '/api/schii',
            'entityNamespace' => 'Phue\\Tests\\Schii\\Stubs',
            'phiiPrefix' => '/custom/generator',
        ]);

        $controller = new PhiiController(
            $renderer,
            new SchemaRegistry(),
            $config,
            $this->createMock(Application::class)
        );

        $response = $controller->index(new Request('GET', '/custom/generator'));

        $this->assertNotNull($captured, 'render() must receive the panel state.');
        $state = $captured['state'] ?? [];

        $this->assertSame('/custom/generator', $state['phiiPrefix'] ?? null);

        // P11 decision A: phiiNamespace is intentionally NOT part of the client state anymore.
        $this->assertArrayNotHasKey('phiiNamespace', $state);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDefaultPrefixesAreStableFallbacks(): void
    {
        $config = new SchiiConfig();
        $this->assertSame('/phii', $config->phiiPrefix);
        $this->assertSame('App\\Models', $config->phiiNamespace);
    }
}