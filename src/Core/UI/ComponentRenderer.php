<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Response;
use RuntimeException;

/**
 * Core component renderer assembling HTML shells, templates, scripts, and state payload.
 *
 * @package Phue\Core\UI
 */
class ComponentRenderer
{
    public function __construct(
        private HtmlShellRenderer $shellRenderer,
        private AssetRegistry $assetRegistry,
        private ?PhueComponentLoader $loader = null
    ) {}

    /**
     * Renders a component by logical name using the component loader, OR directly renders explicit Title/View payload.
     *
     * @param string $nameOrTitle Component logical name (if $viewOrTitleOverride is null/string) or explicit Title.
     * @param View|string|null $viewOrTitleOverride View instance or Title override string.
     */
    public function render(string $nameOrTitle, View|string|null $viewOrTitleOverride = null): Response
    {
        // Named Resolution Path: render('Products') or render('Products', 'Title Override')
        if ($viewOrTitleOverride === null || is_string($viewOrTitleOverride)) {
            if ($this->loader === null) {
                throw new RuntimeException('Cannot render component by name: PhueComponentLoader is not configured.');
            }

            $component = $this->loader->load($nameOrTitle);
            $title = $viewOrTitleOverride ?? $component->title;

            return $this->renderView($title, $component->view);
        }

        // Direct Payload Path: render('Document Title', $viewInstance)
        return $this->renderView($nameOrTitle, $viewOrTitleOverride);
    }

    /**
     * Renders a .phue file directly via filesystem path.
     */
    public function renderFile(string $filePath, ?string $titleOverride = null): Response
    {
        $parser = new PhueTemplateParser();
        $component = $parser->parse($filePath);

        $title = $titleOverride ?? $component->title;

        return $this->renderView($title, $component->view);
    }

    /**
     * Assembles view structures into an HTTP Response.
     */
    private function renderView(string $title, View $view): Response
    {
        foreach ($view->getScripts() as $script) {
            $this->assetRegistry->addScript($script, isModule: true);
        }

        $html = $this->shellRenderer->render(
            title: $title,
            appId: 'app',
            initialState: $view->getState(),
            content: $view->getTemplate(),
            inlineScript: $view->getInlineScript()
        );

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}