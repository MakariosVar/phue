<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Response;

/**
 * Core component renderer responsible for assembling HTML shells, inlining component templates,
 * enqueuing asset scripts, and generating HTTP Responses.
 *
 * @package Phue\Core\UI
 */
class ComponentRenderer
{
    /**
     * ComponentRenderer constructor.
     *
     * @param HtmlShellRenderer $shellRenderer Service responsible for rendering the base HTML page shell.
     * @param AssetRegistry $assetRegistry Service responsible for registering and tracking script dependencies.
     */
    public function __construct(
        private HtmlShellRenderer $shellRenderer,
        private AssetRegistry $assetRegistry
    ) {}

    /**
     * Assembles a View object and returns a fully formed HTML HTTP Response.
     *
     * Performs the following sequence:
     * 1. Enqueues all view script dependencies as ESM modules into the AssetRegistry.
     * 2. Renders the base HTML document shell with initial state serialization and inlined template content.
     *
     * @param string $title The document title to display in the browser <title> tag.
     * @param View $view The View object containing template markup, state, and script dependencies.
     *
     * @return Response Standardized HTTP Response with status 200 and UTF-8 HTML headers.
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
            content: $view->getTemplate()
        );

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}