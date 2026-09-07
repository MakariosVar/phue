<?php

declare(strict_types=1);

namespace Phue\Tests\Auth;

use Phue\Auth\RbacManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RbacManager seed-configuration ingestion and wildcard permissions.
 */
final class RbacManagerSeedingTest extends TestCase
{
    private string $seeded;
    private string $empty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seeded = __DIR__ . '/../Schii/Stubs/rbac_seeded.php';
        $this->empty = __DIR__ . '/../Schii/Stubs/rbac_empty.php';
    }

    public function testConstructorIngestsRolesFromSeedConfig(): void
    {
        $rbac = new RbacManager($this->seeded);

        $this->assertTrue($rbac->checkAccess(1, 'admin.product.read'));
        $this->assertTrue($rbac->checkAccess(1, 'admin.product.delete'));
        $this->assertFalse($rbac->checkAccess(1, 'report.export'));
    }

    public function testConstructorIngestsAssignmentsFromSeedConfig(): void
    {
        $rbac = new RbacManager($this->seeded);

        $this->assertTrue($rbac->checkAccess(2, 'admin.product.read'));
        $this->assertTrue($rbac->checkAccess(2, 'admin.product.update'));
        // editor role does not include delete
        $this->assertFalse($rbac->checkAccess(2, 'admin.product.delete'));
    }

    public function testUnassignedUserIsDenied(): void
    {
        $rbac = new RbacManager($this->seeded);

        $this->assertFalse($rbac->checkAccess(999, 'admin.product.read'));
    }

    public function testWildcardPermissionGrantsAnything(): void
    {
        $rbac = new RbacManager($this->seeded);

        $this->assertTrue($rbac->checkAccess(3, 'admin.anything.whatever'));
        $this->assertTrue($rbac->checkAccess(3, 'system.shutdown'));
    }

    public function testEmptySeedConfigLoadsCleanRbacManager(): void
    {
        $rbac = new RbacManager($this->empty);

        $rbac->addRole('admin', ['admin.product.read']);
        $rbac->assign(1, 'admin');

        $this->assertTrue($rbac->checkAccess(1, 'admin.product.read'));
        $this->assertFalse($rbac->checkAccess(1, 'admin.product.delete'));
    }

    public function testSeedsDoNotLeakAcrossInstances(): void
    {
        $seeded = new RbacManager($this->seeded);
        $empty = new RbacManager($this->empty);

        $this->assertFalse($empty->checkAccess(1, 'admin.product.read'));
        $this->assertTrue($seeded->checkAccess(1, 'admin.product.read'));
    }

    public function testMissingSeedConfigIsIgnored(): void
    {
        $rbac = new RbacManager('/nonexistent/rbac/seed.php');
        $rbac->assign(1, 'role_x');

        $this->assertFalse($rbac->checkAccess(1, 'admin.product.read'));
    }
}