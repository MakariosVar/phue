<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Endpoint;

use Phue\Core\Application;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * S13 — Explorer endpoints MUST be behind the auth gate when requireAuth=true.
 *
 * GET  /api/schii/schema/{entity}/structure
 * POST /api/schii/schema/{entity}/sync        (may execute destructive DDL)
 * GET  /api/schii/schema/{entity}/diff
 *
 * The CRUD routes already route through AuthorizedModelEndpoint when auth is
 * present. The three schema-explorer routes must not stay wired to the raw
 * public endpoints: an anonymous request on any of them is a 401, never the
 * target's payload.
 *
 * @package Phue\Tests\Schii\Endpoint
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class ExplorerAuthGateTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBase = sys_get_temp_dir() . '/phue_schii_authgate_' . uniqid();
        mkdir($this->tmpBase . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpBase);
        parent::tearDown();
    }

    private function writeAppConfig(): void
    {
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

        file_put_contents($this->tmpBase . '/config/app.php', <<<'PHP'
<?php
return [
    'debug' => true,
    'modules' => [
        '\\Phue\\Modules\\Auth\\AuthModule',
        '\\Phue\\Modules\\Schii\\SchiiModule',
    ],
];
PHP);

        file_put_contents($this->tmpBase . '/config/schii.php', <<<'PHP'
<?php
return [
    'apiPrefix' => '/api/schii',
    'entityNamespace' => 'Phue\\Tests\\Schii\\Stubs',
    'requireAuth' => true,
    'middleware' => [],
];
PHP);
    }

    public function testStructureRejectsAnonymousWhenRequireAuth(): void
    {
        $this->writeAppConfig();
        $app = new Application($this->tmpBase);

        $response = $app->handle(new Request('GET', '/api/schii/schema/user/structure'));

        $this->assertNotSame(500, $response->getStatusCode(), 'Must not crash: auth gate must stop the request');
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'structure must be guarded when requireAuth=true'
        );
    }

    public function testSyncRejectsAnonymousWhenRequireAuth(): void
    {
        $this->writeAppConfig();
        $app = new Application($this->tmpBase);

        $response = $app->handle(new Request('POST', '/api/schii/schema/user/sync'));

        $this->assertNotSame(500, $response->getStatusCode(), 'Must not crash: auth gate must stop the request');
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'sync (destructive DDL) must be guarded when requireAuth=true'
        );
    }

    public function testDiffRejectsAnonymousWhenRequireAuth(): void
    {
        $this->writeAppConfig();
        $app = new Application($this->tmpBase);

        $response = $app->handle(new Request('GET', '/api/schii/schema/user/diff'));

        $this->assertNotSame(500, $response->getStatusCode(), 'Must not crash: auth gate must stop the request');
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'diff must be guarded when requireAuth=true'
        );
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}