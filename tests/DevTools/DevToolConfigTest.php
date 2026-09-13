<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;

#[\PHPUnit\Framework\Attributes\Group('integration')]
class DevToolConfigTest extends TestCase
{
    public function testDevToolEnabledShowsToolbar(): void
    {
        $configDir = $this->createConfigDir(debug: true, devTool: true);
        $app = new Application($configDir);

        $app->getRouter()->get('/page', fn () => '<html><body><h1>Hello</h1></body></html>');

        $response = $app->handle(new Request('GET', '/page'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('phue-debug-toolbar', $response->getContent());
        $this->assertStringContainsString('Phue DevTool', $response->getContent());
    }

    public function testDevToolDisabledHidesToolbar(): void
    {
        $configDir = $this->createConfigDir(debug: true, devTool: false);
        $app = new Application($configDir);

        $app->getRouter()->get('/page', fn () => '<html><body><h1>Hello</h1></body></html>');

        $response = $app->handle(new Request('GET', '/page'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('phue-debug-toolbar', $response->getContent());
        $this->assertStringNotContainsString('Phue DevTool', $response->getContent());
    }

    public function testDebugWithoutDevToolShowsNoToolbar(): void
    {
        $configDir = $this->createConfigDir(debug: true, devTool: false);
        $app = new Application($configDir);

        $app->getRouter()->get('/page', fn () => '<html><body><h1>Debug on, toolbar off</h1></body></html>');

        $response = $app->handle(new Request('GET', '/page'));

        $content = $response->getContent();
        $this->assertStringNotContainsString('phue-debug-toolbar', $content);
        $this->assertStringContainsString('Debug on, toolbar off', $content);
    }

    public function testDevToolOffAlsoSkipsJsonToolbar(): void
    {
        $configDir = $this->createConfigDir(debug: true, devTool: false);
        $app = new Application($configDir);

        $app->getRouter()->get('/api/data', fn () => Response::json(['data' => 'test']));

        $response = $app->handle(new Request('GET', '/api/data', [], [], ['Accept' => 'application/json']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('phue-debug-toolbar', $response->getContent());
    }

    private function createConfigDir(bool $debug, bool $devTool): string
    {
        $dir = sys_get_temp_dir() . '/phue_cfg_' . uniqid();
        mkdir($dir . '/config', 0777, true);

        $config = sprintf(
            '<?php return [
    "name" => "Test App",
    "env" => "testing",
    "debug" => %s,
    "devTool" => %s,
    "trustedProxies" => [],
    "modules" => [],
];',
            $debug ? 'true' : 'false',
            $devTool ? 'true' : 'false'
        );

        file_put_contents($dir . '/config/app.php', $config);

        return $dir;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeTempDirs();
    }

    private function removeTempDirs(): void
    {
        $pattern = sys_get_temp_dir() . '/phue_cfg_*';
        foreach (glob($pattern) as $dir) {
            if (is_dir($dir)) {
                $this->rrmdir($dir);
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        $items = glob($dir . '/*');
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->rrmdir($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }
}
