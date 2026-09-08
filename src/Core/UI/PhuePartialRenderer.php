<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use RuntimeException;

/**
 * Class PhuePartialRenderer
 *
 * Renders HTML-first partial components globally via the ComponentLoaderRegistry.
 *
 * @package Phue\Core\UI
 */
class PhuePartialRenderer
{
    /**
     * PhuePartialRenderer constructor.
     *
     * @param ComponentLoaderRegistry $registry Global component loader registry.
     */
    public function __construct(
        private ComponentLoaderRegistry $registry
    ) {}

    /**
     * Renders a partial component by name and returns its resolved HTML string.
     *
     * @param string               $name   Logical component name (e.g., 'PhNavigation').
     * @param array<string, mixed> $params Variables passed into the partial's evaluation context.
     *
     * @return string Fully resolved HTML string.
     *
     * @throws RuntimeException If the partial does not return HTML-first content.
     */
     public function renderPartial(string $name, array $params = []): string
    {
        $component = $this->registry->load($name, $params);
        $view = $component->view;

        if (!$view->hasHtml()) {
            throw new RuntimeException("Component '{$name}' used as partial must operate in HTML-first mode.");
        }

        return $view->getHtml();
    }
}