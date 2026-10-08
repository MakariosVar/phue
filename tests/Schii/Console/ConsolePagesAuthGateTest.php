<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Console;

use Phue\Core\Application;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * Console page auth-gate contract (UX requirement):
 *
 *   GET /schii and GET /phii are PAGE (HTML) routes, not API routes.
 *   When the Schii module runs with requireAuth=true AND the AuthModule is
 *   loaded, an unauthenticated request to either console page MUST be
 *   redirected to /login — it must NOT render the console, and it must
 *   NOT respond with a bare JSON "Unauthorized" body.
 *
 * Expected for anonymous visitors:
 *   /schii -> 302, Location: /login
 *   /phii  -> 302, Location: /login
 *
 * @package Phue\Tests\Schii\Console
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[PHPUnit\Framework\Attributes\Group('auth')]
final class ConsolePagesAuthGateTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpBase = sys_get_temp_dir() . '/phue_console_gate_' . uniqid();
        mkdir($this->tmpBase . '/config', 0777, true);

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
    'prefix' => '/schii',
    'phiiPrefix' => '/phii',
    'entityNamespace' => 'Phue\\Tests\\Schii\\Stubs',
    'requireAuth' => true,
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
        unset($_SESSION['__user_id']);
        session_destroy();

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

    public function testUnauthenticatedSchiiPageRedirectsToLogin(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['__user_id']);

        $app = new Application($this->tmpBase);
        $response = $app->handle(new Request('GET', '/schii'));

        $this->assertSame(302, $response->getStatusCode(), 'Anonymous /schii must redirect, not render');
        $this->assertSame('/login', strtolower('/' . trim((string) ($response->getHeader('Location') ?? ''), '/')));
    }

    public function testUnauthenticatedPhiiPageRedirectsToLogin(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['__user_id']);

        $app = new Application($this->tmpBase);
        $response = $app->handle(new Request('GET', '/phii'));

        $this->assertSame(302, $response->getStatusCode(), 'Anonymous /phii must redirect, not return JSON 401');
        $this->assertSame('/login', strtolower('/' . trim((string) ($response->getHeader('Location') ?? ''), '/')));
    }

    public function testAnonymousPageResponsesAreNeverJsonBodies(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['__user_id']);

        $app = new Application($this->tmpBase);

        foreach (['/schii', '/phii'] as $path) {
            $response = $app->handle(new Request('GET', $path));
            $contentType = (string) $response->getHeader('Content-Type');

            $this->assertStringNotContainsString(
                'application/json',
                $contentType,
                "{$path} is a page route; anonymous access must never yield JSON"
            );
        }
    }
}