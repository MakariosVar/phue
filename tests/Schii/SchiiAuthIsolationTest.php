<?php

declare(strict_types=1);

namespace Phue\Tests\Schii;

use Phue\Core\Application;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * B4 — Auth module isolation contract:
 * Schii must boot and serve BOTH requireAuth modes WITHOUT the AuthModule being
 * loaded. When requireAuth=true but no auth module is present, the module must
 * degrade to public access (or fail loudly during boot) — never crash at runtime.
 *
 * @package Phue\Tests\Schii
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class SchiiAuthIsolationTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBase = sys_get_temp_dir() . '/phue_schii_authiso_' . uniqid();
        mkdir($this->tmpBase . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpBase);
    }

    private function writeConfig(bool $requireAuth): void
    {
        file_put_contents($this->tmpBase . '/config/app.php', <<<PHP
<?php
return [
    'debug' => true,
    'modules' => ['\\Phue\\Modules\\Schii\\SchiiModule'],
];
PHP);

        file_put_contents($this->tmpBase . '/config/schii.php', <<<PHP
<?php
return [
    'apiPrefix' => '/api/schii',
    'entityNamespace' => '{$this->entityNamespace()}',
    'requireAuth' => {$this->boolExpr($requireAuth)},
];
PHP);

        file_put_contents($this->tmpBase . '/config/database.php', <<<PHP
<?php
return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => '/app/database/phue.sqlite',
        ],
    ],
];
PHP);
    }

    private function entityNamespace(): string
    {
        return 'Phue\\Tests\\Schii\\Stubs';
    }

    private function boolExpr(bool $value): string
    {
        return $value ? 'true' : 'false';
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

    public function testBootsWithoutAuthModule(): void
    {
        $this->writeConfig(false);
        $app = new Application($this->tmpBase);

        $response = $app->handle(new Request('GET', '/api/schii/schema/user'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRequireAuthTrueWithoutAuthModuleDoesNotCrash(): void
    {
        $this->writeConfig(true);
        $app = new Application($this->tmpBase);

        $response = $app->handle(new Request('GET', '/api/schii/schema/user'));
        $this->assertNotSame(
            500,
            $response->getStatusCode(),
            'Schii must not crash at runtime when auth module is absent'
        );
    }

    public function testSchemaEndpointServesSchemaWithoutAuthModule(): void
    {
        $this->writeConfig(true);
        $app = new Application($this->tmpBase);

        $response = $app->handle(new Request('GET', '/api/schii/schema/user'));
        $this->assertNotSame(500, $response->getStatusCode());

        $schema = json_decode($response->getContent(), true);
        $this->assertIsArray($schema);
        $this->assertSame('User', $schema['entity']);
    }

    public function testPublicListWithoutCrashWhenAuthAbsent(): void
    {
        $this->writeConfig(true);
        $app = new Application($this->tmpBase);

        $response = $app->handle(new Request('GET', '/api/schii/products', ['q' => '']));
        $this->assertNotSame(
            500,
            $response->getStatusCode(),
            'List endpoint must not crash without auth module'
        );
    }
}