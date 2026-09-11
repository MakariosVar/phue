<?php

declare(strict_types=1);

namespace Phue\Core\Ssr;

/**
 * Interface SsrEngine
 *
 * Contract for high-level Server-Side Rendering execution engines.
 *
 * @package Phue\Core\Ssr
 */
interface SsrEngine
{
    /**
     * Render a component or template string asynchronously via the sidecar service.
     *
     * @param string $template Target template name or component file identifier.
     * @param array<string, mixed> $data Context parameters and props passed into the renderer.
     * @param array<string, mixed> $context Additional execution context (e.g. ['name' => ..., 'uri' => ...]).
     * @return string|null Evaluated HTML string on success, or null on fail-open error.
     */
    public function render(string $template, array $data = [], array $context = []): ?string;
}