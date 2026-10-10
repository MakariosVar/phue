<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Modules\Auth\BaseUser;
use Phue\Modules\Auth\IdentityInterface;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\UsesIsolatedDatabase;

/**
 * Regression-guard + AR identity tests for the BaseUser-backed App\Models\User.
 *
 * Covers the full feature chain introduced in this commit:
 *   - namespace / autoload integrity
 *   - IdentityInterface contract satisfaction
 *   - findIdentityByAuthKey against a real AR table
 *   - getId / getRoles / validatePassword on hydrated models
 */
final class UserModelTest extends TestCase
{
    use UsesIsolatedDatabase;

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = BaseActiveRecord::getConnection();

        // Drop and recreate table for test isolation
        $this->db->execute('DROP TABLE IF EXISTS users');
        $this->db->execute(
            "CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password_hash TEXT, auth_key TEXT, roles TEXT DEFAULT '[]', deleted_at DATETIME NULL)"
        );
    }

    public function testUserAutoloadsAndImplementsIdentityInterface(): void
    {
        $ref = new \ReflectionClass(User::class);
        $this->assertContains(IdentityInterface::class, $ref->getInterfaceNames());
        $this->assertTrue($ref->isSubclassOf(BaseUser::class));
        $instance = new User();
        $this->assertInstanceOf(IdentityInterface::class, $instance);
    }

    public function testFindIdentityByAuthKeyResolvesCorrectUser(): void
    {
        $user = User::create([
            'username' => 'admin',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'auth_key' => 'test-bearer-123',
            'roles' => '["admin"]',
        ]);

        $found = User::findIdentityByAuthKey('test-bearer-123');

        $this->assertInstanceOf(User::class, $found);
        $this->assertSame($user->toArray()['id'], $found->getId());
        $this->assertSame('admin', $found->toArray()['username']);
    }

    public function testFindIdentityByAuthKeyReturnsNullForUnknownToken(): void
    {
        User::create([
            'username' => 'admin',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'auth_key' => 'other-token',
            'roles' => '["admin"]',
        ]);

        $this->assertNull(User::findIdentityByAuthKey('nonexistent'));
    }

    public function testGetIdFromHydratedUser(): void
    {
        $created = User::create([
            'username' => 'editor',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'auth_key' => 'editor-token',
            'roles' => '["editor"]',
        ]);

        $found = User::findIdentityByAuthKey('editor-token');
        $this->assertIsInt($found->getId());
        $this->assertSame($created->toArray()['id'], $found->getId());
    }

    public function testGetRolesDecodesJson(): void
    {
        $created = User::create([
            'username' => 'admin',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'auth_key' => 'admin-token',
            'roles' => '["admin","editor"]',
        ]);

        $found = User::findIdentityByAuthKey('admin-token');
        $this->assertSame(['admin', 'editor'], $found->getRoles());
    }

    public function testValidatePasswordReturnsTrueForCorrectPassword(): void
    {
        $created = User::create([
            'username' => 'admin',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'auth_key' => 'admin-token',
            'roles' => '["admin"]',
        ]);

        $found = User::findIdentityByAuthKey('admin-token');
        $this->assertTrue($found->validatePassword('secret'));
    }

    public function testValidatePasswordReturnsFalseForWrongPassword(): void
    {
        $created = User::create([
            'username' => 'admin',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'auth_key' => 'admin-token',
            'roles' => '["admin"]',
        ]);

        $found = User::findIdentityByAuthKey('admin-token');
        $this->assertFalse($found->validatePassword('wrong'));
    }
}
