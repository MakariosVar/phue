<?php

declare(strict_types=1);

namespace App\Config;

use function Phue\Core\env;
use Phue\Modules\Auth\IdentityInterface;

/**
 * Class NavigationConfig
 *
 * Central configuration repository and query layer for application-wide 
 * navigation elements, brand definitions, topbar/sidebar placements, and action items.
 *
 * @package App\Config
 */
class NavigationConfig
{
    /**
     * Topbar navigation layout placement identifier.
     *
     * @var string
     */
    public const PLACEMENT_TOP = 'topbar';

    /**
     * Sidebar navigation layout placement identifier.
     *
     * @var string
     */
    public const PLACEMENT_SIDEBAR = 'sidebar';

    /**
     * Returns global primary application navigation items filtered by user visibility rules.
     *
     * @param IdentityInterface|null $user Currently authenticated user identity instance, or null if guest.
     *
     * @return array<int, array{label: string, href: string, disabled?: bool, visible?: bool|callable, group?: string}> List of visible navigation items.
     */
    public static function getAppItems(?IdentityInterface $user = null): array
    {
        $allItems = [
            self::item('Home', '/'),
            self::item('Docs', '/docs'),
            self::item('PHUI Components', '/phui'),
        ];

        if ((bool) env('SCHII_ENABLED', true)) {
            $allItems[] =  self::grouped(self::item('Schii', '/schii'), 'Modules');
            $allItems[] =  self::grouped(self::item('Phii', '/phii'), 'Modules');
        }

        return self::filterVisible($allItems, $user);
    }

    /**
     * Builder helper that constructs a baseline navigation item data structure.
     *
     * @param string $label Visible display title for the link.
     * @param string $href  Target URL destination or route path.
     *
     * @return array{label: string, href: string} Structured navigation item array.
     */
    public static function item(string $label, string $href): array
    {
        return [
            'label' => $label,
            'href'  => $href,
        ];
    }

    /**
     * Builder helper that attaches a disabled flag to an existing navigation item.
     *
     * @param array<string, mixed> $item Target navigation item array to modify.
     *
     * @return array<string, mixed> Modified navigation item with disabled flag set.
     */
    public static function disabled(array $item): array
    {
        $item['disabled'] = true;
        return $item;
    }

    /**
     * Builder helper that attaches a dynamic or static visibility rule to a navigation item.
     *
     * @param array<string, mixed> $item Target navigation item array to modify.
     * @param bool|callable        $rule Boolean flag or callable returning bool: fn(?IdentityInterface $u): bool.
     *
     * @return array<string, mixed> Modified navigation item with visible rule attached.
     */
    public static function visible(array $item, bool|callable $rule): array
    {
        $item['visible'] = $rule;
        return $item;
    }

    /**
     * Builder helper that categorizes a navigation item under a specific grouping header.
     *
     * @param array<string, mixed> $item Target navigation item array to modify.
     * @param string               $group Visual header category name.
     *
     * @return array<string, mixed> Modified navigation item with group name attached.
     */
    public static function grouped(array $item, string $group): array
    {
        $item['group'] = $group;
        return $item;
    }

    /**
     * Returns the global brand structure for application headers and sidebars.
     *
     * @return array{label: string, href: string} Navigation item array representing brand title and root path.
     */
    public static function getAppBrand(): array
    {
        return self::item(env('APP_NAME', 'Phue App'), '/');
    }

    /**
     * Returns contextual topbar right-slot user action items filtered by user authorization state.
     *
     * @param IdentityInterface|null $user Currently authenticated user identity instance, or null if guest.
     *
     * @return array<int, array{label: string, href: string, disabled?: bool, visible?: bool|callable, group?: string}> List of visible right-slot action items.
     */
    public static function getAppActions(?IdentityInterface $user = null): array
    {
        $actions = [
            self::visible(
                self::item('Login', '/login'),
                fn (?IdentityInterface $u): bool => $u === null
            ),
            self::visible(
                self::item('Logout', '/logout'),
                fn (?IdentityInterface $u): bool => $u !== null
            ),
        ];

        return self::filterVisible($actions, $user);
    }

    /**
     * Returns the configured global application layout placement strategy.
     *
     * @return string Placement mode ('topbar'|'sidebar').
     */
    public static function getAppPlacement(): string
    {
        return self::PLACEMENT_TOP;
    }

    /**
     * Returns the application layout configuration options including sticky positioning defaults.
     *
     * @return array{sticky: bool} Layout configuration map.
     */
    public static function getAppLayout(): array
    {
        return [
            'sticky' => true,
        ];
    }

    /**
     * Pure static filtering seam that filters navigation items based on their visibility contract rules.
     *
     * @param array<int, array{label: string, href: string, disabled?: bool, visible?: bool|callable, group?: string}> $items Raw list of navigation item arrays.
     * @param IdentityInterface|null $user Currently authenticated identity instance, or null if guest.
     *
     * @return array<int, array{label: string, href: string, disabled?: bool, visible?: bool|callable, group?: string}> Filtered list containing only items visible to current user.
     */
    public static function filterVisible(array $items, ?IdentityInterface $user = null): array
    {
        return array_values(array_filter($items, function (array $item) use ($user): bool {
            if (!array_key_exists('visible', $item)) {
                return true;
            }

            $visible = $item['visible'];

            if (is_callable($visible)) {
                return (bool) $visible($user);
            }

            return (bool) $visible;
        }));
    }
}