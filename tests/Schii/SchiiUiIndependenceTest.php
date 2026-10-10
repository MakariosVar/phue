<?php

declare(strict_types=1);

namespace Phue\Tests\Schii;

use Phue\Core\Application;
use Phue\Core\Database\Connection;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Request;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Modules\Schii\SchiiModule;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use Phue\Tests\Support\TestComponentLoader;

/**
 * Schii module independence & UI surface contracts:
 *  - module entities come from the CONFIGURED namespace (never an App hardcode),
 *  - the module consumes the APPLICATION config (apiPrefix, namespace),
 *  - the module owns its frontend (.phue under src/Modules/Schii/Frontend) and registers its page.
 *
 * @package Phue\Tests\Schii
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class SchiiUiIndependenceTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpBase = sys_get_temp_dir() . '/phue_schii_indep_' . uniqid();
        mkdir($this->tmpBase . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['__user_id']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->rmTree($this->tmpBase);
    }

    private function writeConfig(string $schiiApiPrefix, string $entityNamespace): void
    {
        file_put_contents($this->tmpBase . '/config/app.php', <<<PHP
<?php
return [
    'debug' => true,
    'modules' => ['\\Phue\\Modules\\Auth\\AuthModule', '\\Phue\\Modules\\Schii\\SchiiModule'],
];
PHP);

        file_put_contents($this->tmpBase . '/config/database.php', <<<PHP
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

        file_put_contents($this->tmpBase . '/config/schii.php', <<<PHP
<?php
return [
    'apiPrefix' => '{$schiiApiPrefix}',
    'entityNamespace' => '{$entityNamespace}',
    'requireAuth' => false,
];
PHP);
    }

    private function addPhuiLoader(Application $app): void
    {
        $registry = $app->getContainer()->get(ComponentLoaderRegistry::class);
        $registry->addLoader(TestComponentLoader::make(dirname(__DIR__, 2) . '/src/Core/UI/Phui'));
        $registry->addLoader(TestComponentLoader::make(dirname(__DIR__, 2) . '/App/Frontend'));
    }

    private function authedSession(Application $app): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $db = $app->getContainer()->get(Connection::class);
        $db->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            auth_key TEXT,
            roles TEXT
        )');
        $db->execute(
            "INSERT INTO users (username, password_hash, auth_key, roles) VALUES ('admin', ?, 'admin-token', '[\"admin\"]')",
            [password_hash('secret', PASSWORD_BCRYPT)]
        );

        $_SESSION['__user_id'] = 1;
    }

    public function testModuleHonorsApplicationConfigPrefixAndNamespace(): void
    {
        $this->writeConfig('/custom', 'Phue\\Tests\\Schii\\Stubs');

        $app = new Application($this->tmpBase);

        $schema = $app->handle(new Request('GET', '/custom/schema/user'));
        $this->assertSame(200, $schema->getStatusCode());
        $decoded = json_decode($schema->getContent(), true);
        $this->assertSame('User', $decoded['entity']);

        $unknown = $app->handle(new Request('GET', '/custom/schema/products'));
        $this->assertSame(404, $unknown->getStatusCode());

        $defaultPrefix = $app->handle(new Request('GET', '/api/schii/schema/user'));
        $this->assertSame(404, $defaultPrefix->getStatusCode());
    }

    public function testModuleSourceContainsNoApplicationNamespaceReference(): void
    {
        $forbidden = ['App\Models', 'use App\\', 'new App\\'];
        $hits = [];

        $files = glob(dirname(__DIR__, 2) . '/src/Modules/Schii/**/*.php') ?: [];
        $files = array_merge($files, glob(dirname(__DIR__, 2) . '/src/Modules/Schii/*.php') ?: []);

        foreach (array_unique($files) as $file) {
            $source = strip_comments((string) file_get_contents($file));
            foreach ($forbidden as $needle) {
                if (str_contains($source, $needle)) {
                    $hits[] = "{$needle} in {$file}";
                }
            }
        }

        $this->assertSame([], $hits, 'Schii module must not reference an application namespace at runtime');
    }

    public function testSchemaDiscoveryListsNamespaceEntities(): void
    {
        $this->writeConfig('/api/schii', 'Phue\\Tests\\Schii\\Stubs');

        $app = new Application($this->tmpBase);

        $listing = $app->handle(new Request('GET', '/api/schii/schema'));
        $this->assertSame(200, $listing->getStatusCode());
        $decoded = json_decode($listing->getContent(), true);
        $this->assertContains('user', $decoded['entities'] ?? []);

        $entity = $app->handle(new Request('GET', '/api/schii/schema/user'));
        $this->assertSame(200, $entity->getStatusCode());
        $this->assertSame('User', json_decode($entity->getContent(), true)['entity']);
    }

    public function testModuleRegistersSchiiPageFromItsOwnFrontend(): void
    {
        $this->writeConfig('/api/schii', 'Phue\\Tests\\Schii\\Stubs');

        $app = new Application($this->tmpBase);
        $this->authedSession($app);
        $this->addPhuiLoader($app);

        $page = $app->handle(new Request('GET', '/schii'));
        $this->assertSame(200, $page->getStatusCode());
        $content = $page->getContent();
        $this->assertStringContainsString('<div id="app">', $content);

        preg_match('#/assets/phue/Schii\.[a-f0-9]{8}\.phue\.js#', $content, $m);
        $this->assertArrayHasKey(0, $m, 'Schii page must emit its own module URL');

        $module = $app->handle(new Request('GET', $m[0]));
        $this->assertSame(200, $module->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $module->getHeader('Content-Type'));
        $this->assertStringContainsString('createPhueApp', $module->getContent());
        $this->assertStringContainsString("/schema", $module->getContent());
        $this->assertStringNotContainsString(".mount('#app')", $module->getContent());
    }

    public function testSchiiStateCarriesConfiguredApiPrefix(): void
    {
        // Non-default prefix: the console must discover the real one, not /
        // api/schii
        $this->writeConfig('/custom/api', 'Phue\\Tests\\Schii\\Stubs');

        $app = new Application($this->tmpBase);
        $this->authedSession($app);
        $this->addPhuiLoader($app);

        $page = $app->handle(new Request('GET', '/schii'));
        $this->assertSame(200, $page->getStatusCode());

        $content = $page->getContent();

        preg_match('/window\\.__INITIAL_STATE__ = (\\{.*?\\});/', $content, $m);
        $this->assertArrayHasKey(1, $m, 'Schii page must inject window.__INITIAL_STATE__');
        $state = json_decode($m[1], true);

        $this->assertSame(
            '/custom/api',
            $state['apiPrefix'] ?? null,
            'Schii page must carry the configured apiPrefix (never the hardcoded default)',
        );

        $this->assertStringNotContainsString(
            '"apiPrefix":"/api/schii"',
            $content,
            'The hardcoded /api/schii default must not leak into the console page state',
        );
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob("{$dir}/*") ?: [] as $entry) {
            is_dir($entry) ? $this->rmTree($entry) : unlink($entry);
        }

        rmdir($dir);
    }
}

/**
 * Removes PHP comments/docblocks from source, keeping runtime code intact.
 * Used by the UI-independence scan so PHPDoc examples (e.g. App\Models in a
 * docblock) don't trip a runtime-coupling assertion.
 */
function strip_comments(string $source): string
{
    $tokens = token_get_all($source);
    $clean = '';

    foreach ($tokens as $token) {
        if (is_array($token)) {
            [$id, $text] = $token;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                $clean .= "\n";
                continue;
            }
            $clean .= $text;
        } else {
            $clean .= $token;
        }
    }

    return $clean;
}