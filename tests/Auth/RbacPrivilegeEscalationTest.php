<?php

declare(strict_types=1);

namespace Phue\Tests\Auth;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\Auth\RbacManager;

/**
 * Regression gate for the RBAC privilege-escalation chain.
 *
 * Background
 * ----------
 * RbacManager::checkAccess() resolves the role set like this:
 *
 *     $userRoles = !empty($explicitRoles)
 *         ? $explicitRoles
 *         : ($this->assignments[$userContext] ?? []);
 *
 * Both production call sites pass the roles read from the *identity row*:
 *
 *   - UserAccessor::can()                -> $identity->getRoles()
 *   - AuthorizedModelEndpoint (line 167) -> $user->getRoles()
 *
 * Original defect
 * ---------------
 * App\Models\User::$roles was declared `writable: true`, so a client holding
 * only `admin.User.update` could reach the authorization input itself:
 *
 *   user with admin.User.update
 *     -> PUT /api/schii/users/2 {"roles":["admin"]}
 *     -> next request: checkAccess(2, 'anything', ['admin']) === true
 *
 * Accepted design (option β)
 * --------------------------
 * The roles COLUMN is the authority — it is per-user and dynamic, which is the
 * entire reason it exists; config/rbac_seeding.php is only the bootstrap for
 * the first administrator. So RbacManager is deliberately NOT hardened here,
 * and two rejected alternatives are recorded because both were measured:
 *
 *   α) drop $explicitRoles entirely  -> the roles column becomes dead, dynamic
 *      per-user roles stop working at all, and 5 pre-existing authorization
 *      tests (PhiiAuthGateTest, AuthorizedSchemaExplorerEndpointTest x2,
 *      SessionAuthFlowTest, UserAuthGateTest) return 403 where they expect 200.
 *
 *   β) intersect() with the server assignments -> the column can then only ever
 *      REMOVE authority, never grant it, and a user in the database with
 *      roles:["admin"] but no seeding entry has no permissions and no way to
 *      acquire them from the application. 4 escalation tests here went red.
 *
 * The security boundary therefore moved to the WRITE PATH, and that is where
 * the gate lives now — see tests/App/AuthorisationGrantWritePathTest.php,
 * which asserts App\Models\User::$roles cannot be reached through the generic
 * CRUD endpoint. This file guards the RbacManager contract itself.
 *
 * Consequence to keep in mind: because the column is authoritative, a wrong
 * value in it now removes access by design. The only way to write it is a
 * privileged path, so availability is the write path's problem, not this
 * class's. testIdentityRolesColumnIsTheAuthorityWhenPresent pins that
 * decision so a future change to it is deliberate.
 *
 * @package Phue\Tests\Auth
 */
#[Group('unit')]
#[Group('security')]
final class RbacPrivilegeEscalationTest extends TestCase
{
    private RbacManager $rbac;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rbac = new RbacManager(null);

        $this->rbac->addRole('admin', ['*']);
        $this->rbac->addRole('editor', [
            'admin.product.read',
            'admin.product.update',
        ]);
        $this->rbac->addRole('viewer', ['admin.product.read']);

        // Server-side truth, exactly as config/rbac_seeding.php declares it.
        $this->rbac->assign(1, 'admin');
        $this->rbac->assign(2, 'editor');
    }

    // ---------------------------------------------------------------------
    // The contract this class now holds, and the sharp edges of it
    // ---------------------------------------------------------------------

    /**
     * Design pin: when the identity supplies roles, THOSE roles decide.
     *
     * This is the accepted fix (option β), asserted rather than assumed. If
     * someone reintroduces intersect() here, this test goes red instead of
     * silently changing the authorization model of the whole application.
     */
    #[Group('p0')]
    public function testIdentityRolesColumnIsTheAuthorityWhenPresent(): void
    {
        $this->assertTrue(
            $this->rbac->checkAccess(2, 'admin.product.read', ['editor']),
            'The roles column is the authority under the accepted design and must decide access.'
        );

        $this->assertFalse(
            $this->rbac->checkAccess(2, 'admin.user.delete', ['editor']),
            'A role grants exactly its own permission set and nothing more.'
        );
    }

    /**
     * A role NAME that was never declared grants nothing, even when the
     * identity hands it over claiming authority.
     *
     * This is the part of the original escalation that the accepted design
     * still blocks: the wildcard only exists because `admin` was registered
     * with '*'. An unregistered name must not reach the wildcard by accident.
     */
    #[Group('p0')]
    public function testUndeclaredRoleNameGrantsNothing(): void
    {
        foreach (['superuser', 'root', 'admin ', '*', 'ADMIN'] as $fabricated) {
            $this->assertFalse(
                $this->rbac->checkAccess(999, 'admin.user.delete', [$fabricated]),
                "An undeclared role name [{$fabricated}] granted access."
            );
        }
    }

    /**
     * The wildcard is contained to the role that declares it.
     */
    #[Group('p0')]
    public function testWildcardIsContainedToTheRoleThatDeclaresIt(): void
    {
        $this->assertTrue($this->rbac->checkAccess(1, 'literally.anything'));

        foreach (['editor', 'viewer', 'senior', 'undefined'] as $role) {
            $this->assertFalse(
                $this->rbac->checkAccess(999, 'literally.anything', [$role]),
                "Role [{$role}] inherited the admin wildcard it never declared."
            );
        }
    }

    /**
     * Empty and absent roles must fall through to the server assignments —
     * that is what makes config/rbac_seeding.php the bootstrap for the first
     * administrator instead of dead code.
     */
    #[Group('p0')]
    public function testServerSideAssignmentsStillGovernWhenIdentityHasNoRoles(): void
    {
        $this->assertTrue($this->rbac->checkAccess(1, 'admin.whatever', []));
        $this->assertTrue($this->rbac->checkAccess(2, 'admin.product.update', []));
        $this->assertFalse($this->rbac->checkAccess(999, 'admin.product.read', []));
    }

    /**
     * A role handed in that the server did not assign is honoured for its own
     * permissions and no others — recorded explicitly so the reliance on the
     * write path is visible in this file too, not only in the app-level gate.
     */
    #[Group('p0')]
    public function testRoleGrantsNothingOutsideItsOwnPermissionSet(): void
    {
        $this->assertTrue(
            $this->rbac->checkAccess(2, 'admin.product.read', ['editor']),
            'Precondition: the editor role must grant its own read permission.'
        );

        $this->assertFalse(
            $this->rbac->checkAccess(2, 'admin.user.read', ['editor']),
            'The editor role was honoured for a permission it does not hold.'
        );
    }

    // ---------------------------------------------------------------------
    // Role inheritance must not become an escalation vector either
    // ---------------------------------------------------------------------

    /**
     * addChild() wires parent -> child inheritance. A child role must not be
     * usable as a substitute for the parent.
     */
    #[Group('p0')]
    public function testInheritedChildRoleDoesNotGrantParentPrivileges(): void
    {
        // senior inherits editor's permissions.
        $this->rbac->addRole('senior', []);
        $this->rbac->addChild('senior', 'editor');
        $this->rbac->assign(3, 'senior');

        $this->assertTrue(
            $this->rbac->checkAccess(3, 'admin.product.update', []),
            'Precondition: role inheritance must work.'
        );
        $this->assertFalse(
            $this->rbac->checkAccess(3, 'admin.user.delete', ['senior']),
            'An inherited role leaked a privilege the holder does not have.'
        );
    }

    /**
     * A cycle in the inheritance graph must terminate, not recurse forever.
     */
    #[Group('p0')]
    public function testCyclicRoleInheritanceTerminates(): void
    {
        $this->rbac->addRole('a', []);
        $this->rbac->addRole('b', []);
        $this->rbac->addChild('a', 'b');
        $this->rbac->addChild('b', 'a');
        $this->rbac->assign(4, 'a');

        $this->assertFalse($this->rbac->checkAccess(4, 'admin.user.read', ['a']));
    }

    // ---------------------------------------------------------------------
    // Non-regression: the legitimate authorization paths
    // ---------------------------------------------------------------------

    /**
     * A role held legitimately on the server grants its permissions.
     */
    #[Group('p0')]
    public function testServerAssignedRoleGrantsItsPermissions(): void
    {
        $this->assertTrue($this->rbac->checkAccess(1, 'anything.at.all'));
        $this->assertTrue($this->rbac->checkAccess(2, 'admin.product.read'));
        $this->assertTrue($this->rbac->checkAccess(2, 'admin.product.update'));
        $this->assertFalse($this->rbac->checkAccess(2, 'admin.product.delete'));
    }

    /**
     * A denied permission stays denied for a user with no roles at all.
     */
    #[Group('p0')]
    public function testDenialIsTheDefaultForUnknownUsers(): void
    {
        $this->assertFalse($this->rbac->checkAccess(12345, 'admin.product.read'));
        $this->assertFalse($this->rbac->checkAccess(12345, 'admin.product.read', []));
    }

    /**
     * Multiple server-side roles for one user are unioned, not intersected.
     */
    #[Group('p0')]
    public function testMultipleServerAssignedRolesAreUnioned(): void
    {
        $this->rbac->assign(5, 'viewer');
        $this->rbac->assign(5, 'editor');

        $this->assertTrue($this->rbac->checkAccess(5, 'admin.product.read', []));
        $this->assertTrue($this->rbac->checkAccess(5, 'admin.product.update', []));
        $this->assertFalse($this->rbac->checkAccess(5, 'admin.user.read', []));
    }
}
