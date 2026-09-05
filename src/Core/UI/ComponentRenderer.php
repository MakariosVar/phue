<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Response;

/**
 * Core component renderer assembling HTML shells, inline templates, scripts, and state payload.
 *
 * @package Phue\Core\UI
 */
class ComponentRenderer
{
    public function __construct(
        private HtmlShellRenderer $shellRenderer,
        private AssetRegistry $assetRegistry
    ) {}

    /**
     * Assembles a View object and returns a fully formed HTML HTTP Response.
     *
     * @param string $title Document title.
     * @param View $view View object payload.
     * @return Response HTTP response.
     */
    public function render(string $title, View $view): Response
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

    /**
     * Convenience helper to parse and render a .phue SFC file directly.
     *
     * @param string $filePath Path to the .phue file.
     * @param string|null $titleOverride Optional document title override.
     * @return Response
     */
    public function renderFile(string $filePath, ?string $titleOverride = null): Response
    {
        $parser = new PhueTemplateParser();
        $component = $parser->parse($filePath);

        $title = $titleOverride ?? $component->title;

        return $this->render($title, $component->view);
    }
}