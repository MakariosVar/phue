<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use App\Config\NavigationConfig;
use Phue\Modules\Auth\IdentityInterface;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for NavigationConfig v2 — placement strategy and
 * user-based visibility rules.
 *
 * Locks:
 *  - global placement resolves to a documented strategy ('topbar' default),
 *  - the pure visibility filter honors bool and callable 'visible' rules,
 *  - callable rules receive the current identity (null for guests),
 *  - filtered results are re-indexed as a flat list,
 *  - every 'visible' value is bool|callable (schema contract),
 *  - items without a 'visible' rule are always kept.
 *
 * @package Phue\Tests\App
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class NavigationConfigTest extends TestCase
{
    public function testDefaultPlacementIsTopbar(): void
    {
        $this->assertSame('topbar', NavigationConfig::getAppPlacement());
    }

    public function testPlacementConstantsAreTopbarAndSidebar(): void
    {
        $this->assertSame('topbar', NavigationConfig::PLACEMENT_TOP);
        $this->assertSame('sidebar', NavigationConfig::PLACEMENT_SIDEBAR);
    }

    public function testPlacementMatchesDeclaredConstants(): void
    {
        $this->assertContains(NavigationConfig::getAppPlacement(), [
            NavigationConfig::PLACEMENT_TOP,
            NavigationConfig::PLACEMENT_SIDEBAR,
        ]);
    }

    public function testItemBuilderReturnsBaseItem(): void
    {
        $this->assertSame(
            ['label' => 'Home', 'href' => '/'],
            NavigationConfig::item('Home', '/')
        );
    }

    public function testItemBuilderParsesMethodPrefix(): void
    {
        $postItem = NavigationConfig::item('Logout', 'POST /logout');
        $this->assertSame([
            'label'  => 'Logout',
            'href'   => '/logout',
            'method' => 'POST',
        ], $postItem);

        $getItem = NavigationConfig::item('Profile', 'GET /profile');
        $this->assertSame([
            'label' => 'Profile',
            'href'  => '/profile',
        ], $getItem);

        $lowercasePost = NavigationConfig::item('Logout', 'post /logout');
        $this->assertSame([
            'label'  => 'Logout',
            'href'   => '/logout',
            'method' => 'POST',
        ], $lowercasePost);

        $deleteItem = NavigationConfig::item('Delete', 'DELETE /account');
        $this->assertSame([
            'label'  => 'Delete',
            'href'   => '/account',
            'method' => 'DELETE',
        ], $deleteItem);

        $plainItem = NavigationConfig::item('Home', '/');
        $this->assertSame([
            'label' => 'Home',
            'href'  => '/',
        ], $plainItem);
    }

    public function testDisabledBuilderAddsDisabledFlag(): void
    {
        $item = NavigationConfig::disabled(NavigationConfig::item('X', '/x'));

        $this->assertSame(true, $item['disabled']);
        $this->assertSame('X', $item['label']);
    }

    public function testVisibleBuilderAcceptsCallableAndBool(): void
    {
        $callableItem = NavigationConfig::visible(NavigationConfig::item('X', '/x'), fn() => true);
        $this->assertTrue(is_callable($callableItem['visible']));

        $boolItem = NavigationConfig::visible(NavigationConfig::item('X', '/x'), false);
        $this->assertSame(false, $boolItem['visible']);
    }

    public function testGroupedBuilderSetsGroup(): void
    {
        $this->assertSame(
            ['label' => 'Docs', 'href' => '/docs', 'group' => 'Learn'],
            NavigationConfig::grouped(NavigationConfig::item('Docs', '/docs'), 'Learn')
        );
    }

    public function testGroupedAndVisibleItemsPassThroughVisibilityFilter(): void
    {
        $items = NavigationConfig::filterVisible([
            NavigationConfig::grouped(NavigationConfig::item('Docs', '/docs'), 'Learn'),
            NavigationConfig::visible(NavigationConfig::item('Admin', '/admin'), fn(?IdentityInterface $u) => $u !== null),
        ], $this->identity('admin'));

        $this->assertSame(['Docs', 'Admin'], array_column($items, 'label'));
        $this->assertSame('Learn', $items[0]['group'] ?? null);
        $this->assertArrayNotHasKey('group', $items[1]);
    }

    public function testAppBrandResolvesLabelAndHref(): void
    {
        $brand = NavigationConfig::getAppBrand();

        $this->assertArrayHasKey('label', $brand);
        $this->assertArrayHasKey('href', $brand);
        $this->assertNotSame('', $brand['label']);
        $this->assertSame('/', $brand['href']);
    }

    public function testAppActionsForGuestExposePublicOnly(): void
    {
        $actions = NavigationConfig::getAppActions(null);

        $this->assertContains('Login', array_column($actions, 'label'));
        $this->assertNotContains('Logout', array_column($actions, 'label'));
    }

    public function testAppActionsForIdentityExposeAuthedActions(): void
    {
        $actions = NavigationConfig::getAppActions($this->identity('admin'));

        $this->assertContains('Logout', array_column($actions, 'label'));
        $this->assertNotContains('Login', array_column($actions, 'label'));

        $logout = null;
        foreach ($actions as $action) {
            if ($action['label'] === 'Logout') {
                $logout = $action;
                break;
            }
        }
        $this->assertNotNull($logout);
        $this->assertSame('/logout', $logout['href']);
        $this->assertSame('POST', $logout['method'] ?? null);
    }

    public function testAppItemsAreReachableAsFlatList(): void
    {
        $items = NavigationConfig::getAppItems(null);

        $this->assertNotEmpty($items);
        $this->assertSame(range(0, count($items) - 1), array_keys($items));
        foreach ($items as $item) {
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('href', $item);
        }
    }

    public function testItemsWithoutVisibleRuleAreAlwaysKept(): void
    {
        $items = NavigationConfig::filterVisible([
            ['label' => 'Public', 'href' => '/public'],
            ['label' => 'Other', 'href' => '/other'],
        ], null);

        $this->assertSame(['Public', 'Other'], array_column($items, 'label'));
    }

    public function testVisibleBooleanRuleIsApplied(): void
    {
        $items = NavigationConfig::filterVisible([
            ['label' => 'Public', 'href' => '/public'],
            ['label' => 'Hidden', 'href' => '/hidden', 'visible' => false],
            ['label' => 'Always', 'href' => '/always', 'visible' => true],
        ], null);

        $this->assertSame(['Public', 'Always'], array_column($items, 'label'));
    }

    public function testVisibleCallableHidesForGuest(): void
    {
        $items = NavigationConfig::filterVisible([
            ['label' => 'Member', 'href' => '/member', 'visible' => fn(?IdentityInterface $user) => $user !== null],
        ], null);

        $this->assertSame([], array_column($items, 'label'));
    }

    public function testVisibleCallableShowsForMatchingIdentity(): void
    {
        $items = NavigationConfig::filterVisible([
            ['label' => 'Admin', 'href' => '/admin', 'visible' => fn(?IdentityInterface $user) => $user?->getAuthKey() === 'admin'],
        ], $this->identity('admin'));

        $this->assertSame(['Admin'], array_column($items, 'label'));
    }

    public function testVisibleCallableDoesNotShowForDifferentIdentity(): void
    {
        $items = NavigationConfig::filterVisible([
            ['label' => 'Admin', 'href' => '/admin', 'visible' => fn(?IdentityInterface $user) => $user?->getAuthKey() === 'admin'],
        ], $this->identity('user'));

        $this->assertSame([], array_column($items, 'label'));
    }

    public function testFilteredResultIsReindexedAsFlatList(): void
    {
        $items = NavigationConfig::filterVisible([
            ['label' => 'Drop', 'href' => '/drop', 'visible' => false],
            ['label' => 'Keep', 'href' => '/keep'],
        ], null);

        $this->assertSame([0 => ['label' => 'Keep', 'href' => '/keep']], $items);
    }

    public function testEveryVisibleRuleIsBoolOrCallable(): void
    {
        $items = NavigationConfig::filterVisible([
            ['label' => 'A', 'href' => '/a', 'visible' => true],
            ['label' => 'B', 'href' => '/b', 'visible' => false],
            ['label' => 'C', 'href' => '/c', 'visible' => fn() => true],
            ['label' => 'D', 'href' => '/d'],
        ], null);

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            if (!array_key_exists('visible', $item)) {
                continue;
            }
            $this->assertTrue(
                is_bool($item['visible']) || is_callable($item['visible']),
                'visible rule must be bool|callable'
            );
        }
    }

    private function identity(string $authKey): IdentityInterface
    {
        return new class($authKey) implements IdentityInterface {
            public function __construct(private readonly string $authKey) {}

            public function getId(): int|string
            {
                return 1;
            }

            public static function findIdentity(int|string $id): ?self
            {
                return null;
            }

            public function getAuthKey(): ?string
            {
                return $this->authKey;
            }

            public static function findByUsername(string $username): ?self
            {
                return new self($username);
            }
        };
    }
}