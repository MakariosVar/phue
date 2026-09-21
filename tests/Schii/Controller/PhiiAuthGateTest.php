<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Controller;

use Phue\Core\Application;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Modules\Auth\Authenticator\SessionAuthenticator;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\RbacManager;
use Phue\Modules\Schii\Config\SchiiConfig;
use Phue\Modules\Schii\Controller\PhiiController;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Auth\Stubs\ExplorerAuthUser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P12/P1 — the Phii code generator writes arbitrary files; when auth is wired
 * in, every action (index/generate/preview/apply) must demand an authenticated
 * user holding `admin.phii.manage`. Anonymous, forbidden and allowed flows.
 *
 * @package Phue\Tests\Schii\Controller
 */
#[Group('unit')]
final class PhiiAuthGateTest extends TestCase
{
    private string $dump;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dump = sys_get_temp_dir() . '/phue_phii_authgate_' . uniqid();
        @mkdir($this->dump, 0755, true);

        $this->db = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        BaseActiveRecord::setConnection($this->db);

        $this->db->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            auth_key TEXT,
            roles TEXT
        )');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (is_dir($this->dump)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dump, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink()
                    ? rmdir($item->getPathname())
                    : unlink($item->getPathname());
            }
            rmdir($this->dump);
        }
        parent::tearDown();
    }

    private function seedUser(string $username, array $roles): ExplorerAuthUser
    {
        $user = new ExplorerAuthUser();
        $user->fill([
            'username' => $username,
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'auth_key' => $username . '-token',
            'roles' => json_encode($roles),
        ]);
        $user->save();
        return $user;
    }

    private function controller(?callable $rbacConfig = null): PhiiController
    {
        $config = SchiiConfig::fromArray([
            'apiPrefix' => '/api/schii',
            'entityNamespace' => 'Phue\\Tests\\Schii\\Stubs',
            'exportDir' => $this->dump,
        ]);

        $authManager = new AuthManager([new SessionAuthenticator(ExplorerAuthUser::class, '__user_id')]);
        $rbacManager = new RbacManager();
        if ($rbacConfig !== null) {
            $rbacConfig($rbacManager);
        }

        return new PhiiController(
            $this->createMock(\Phue\Core\UI\ComponentRenderer::class),
            new SchemaRegistry(),
            $config,
            $this->createMock(Application::class),
            $authManager,
            $rbacManager
        );
    }

    private function samplePlan(): array
    {
        return [[
            'path' => "{$this->dump}/App/Models/GateProbe.php",
            'content' => '<?php echo "must never land without admin.phii.manage";',
        ]];
    }

    public function testIndexAnonymousIsRejected(): void
    {
        $response = $this->controller()->index(new Request('GET', '/phii'));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testApplyAnonymousIsRejected(): void
    {
        $response = $this->controller()->apply(new Request('POST', '/phii/apply', [], ['plan' => $this->samplePlan()]));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFileDoesNotExist($this->dump . '/App/Models/GateProbe.php', 'anonymous apply must not write');
    }

    public function testApplyForbiddenRoleIsRejected(): void
    {
        $this->seedUser('editor', ['editor']);
        $_SESSION['__user_id'] = 1;

        $controller = $this->controller(static function (RbacManager $rbac): void {
            $rbac->addRole('editor', ['admin.user.read']);
        });

        $response = $controller->apply(new Request('POST', '/phii/apply', [], ['plan' => $this->samplePlan()]));

        $this->assertSame(403, $response->getStatusCode(), 'a user without admin.phii.manage must be forbidden');
        $this->assertFileDoesNotExist($this->dump . '/App/Models/GateProbe.php');
    }

    public function testApplyAdminWithPermissionIsAllowed(): void
    {
        $this->seedUser('admin', ['admin']);
        $_SESSION['__user_id'] = 1;

        $controller = $this->controller(static function (RbacManager $rbac): void {
            $rbac->addRole('admin', ['*']);
        });

        $response = $controller->apply(new Request('POST', '/phii/apply', [], ['plan' => $this->samplePlan()]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFileExists($this->dump . '/App/Models/GateProbe.php');
    }
}