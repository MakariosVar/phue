<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use RuntimeException;
use Throwable;

/**
 * Class PhuePartialRenderer
 *
 * Renders partial components globally via the ComponentLoaderRegistry, supporting both
 * static HTML-first views and V8-compiled SSR render fallbacks.
 *
 * @package Phue\Core\UI
 */
class PhuePartialRenderer
{
    /**
     * PhuePartialRenderer constructor.
     *
     * @param ComponentLoaderRegistry $registry Global component loader registry.
     * @param ComponentRenderer|null $componentRenderer Optional renderer instance for SSR execution.
     */
    public function __construct(
        private ComponentLoaderRegistry $registry,
        private ?ComponentRenderer $componentRenderer = null
    ) {}

    /**
     * Renders a partial component by name and returns its resolved HTML string.
     *
     * @param string                $name   Logical component name (e.g., 'PhNavigation').
     * @param array<string, mixed> $params Variables passed into the partial's evaluation context.
     *
     * @return string Fully resolved HTML string or an HTML comment error fallback on failure.
     *
     * @throws Throwable If rendering fails and the environment is 'test'.
     * @throws RuntimeException If the partial cannot be resolved or fails to produce HTML.
     */
    public function renderPartial(string $name, array $params = []): string
    {
        try {
            $component = $this->registry->load($name, $params);

            if ($component === null) {
                throw new RuntimeException("Component '{$name}' could not be resolved by any registered loader.");
            }

            $view = $component->view;

            // 1. Direct Static HTML Mode
            if ($view->hasHtml()) {
                return $view->getHtml();
            }

            // 2. SSR Fallback Mode
            if ($this->componentRenderer !== null) {
                $rendered = $this->componentRenderer->renderComponent($name, $params);
                if (!empty($rendered->html)) {
                    return $rendered->html;
                }
            }

            throw new RuntimeException("Component '{$name}' used as partial must provide static HTML or be rendered via SSR.");
        } catch (Throwable $e) {
            error_log("[Phue Partial Error] Failed rendering '{$name}': " . $e->getMessage());

            // Re-throw during testing to prevent silent failures
            if (defined('PHUE_ENV') && PHUE_ENV === 'test') {
                throw $e;
            }

            return sprintf('<!-- Partial [%s] rendering failed -->', htmlspecialchars($name, ENT_QUOTES, 'UTF-8'));
        }
    }
}