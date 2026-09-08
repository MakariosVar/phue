<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Class NavigationConfig
 *
 * Provides shared navigation structures per context.
 */
class NavigationConfig
{
    /**
     * @return array<int, array{label: string, href: string, disabled?: bool}>
     */
    public static function getAppItems(): array
    {
        return [
            ['label' => 'Home', 'href' => '/'],
            ['label' => 'Docs', 'href' => '/docs'],
            ['label' => 'UI', 'href' => '/ui'],
            ['label' => 'Products', 'href' => '/products'],
        ];
    }

    /**
     * @return array<int, array{label: string, href: string, disabled?: bool}>
     */
    public static function getSchiiItems(): array
    {
        return [
            ['label' => 'Schii - AR Dashboard', 'href' => '/schii'],
            ['label' => 'Phii - Code Generation', 'href' => '/schii/phii'],
        ];
    }
}