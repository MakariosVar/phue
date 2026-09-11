<?php

declare(strict_types=1);

namespace Phue\Core\Ssr;

/**
 * Class NullSsrEngine
 *
 * Fallback Null Object implementation of SsrEngine.
 * Used when Server-Side Rendering is globally disabled in configuration.
 *
 * @package Phue\Core\Ssr
 */
class NullSsrEngine implements SsrEngine
{
    /**
     * Always returns null to signal client-side fallback rendering.
     *
     * @param string $template Template identifier.
     * @param array<string, mixed> $staet Context parameters.
     * @param array<string, mixed> $context Additional context.
     * @return string|null Always returns null.
     */
    public function render(string $template, array $state = [], array $context = []): ?string
    {
        return null;
    }
}