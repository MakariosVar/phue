<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Response;
use RuntimeException;

class ComponentRenderer
{
    public function __construct(
        private HtmlShellRenderer $shellRenderer,
        private AssetRegistry $assetRegistry,
        private ?PhueComponentLoader $loader = null
    ) {}

    public function render(string $nameOrTitle, View|string|null $viewOrTitleOverride = null): Response
    {
        // Named Resolution Path (e.g., render('Products')) -> External Module Mode
        if ($viewOrTitleOverride === null || is_string($viewOrTitleOverride)) {
            if ($this->loader === null) {
                throw new RuntimeException('Cannot render component by name: PhueComponentLoader is not configured.');
            }

            $component = $this->loader->load($nameOrTitle);
            $title = $viewOrTitleOverride ?? $component->title;

            return $this->renderComponentAsModule($nameOrTitle, $title, $component->view);
        }

        // Direct BC Path: render('Title', $viewInstance) -> Remains Inline Script
        return $this->renderView($nameOrTitle, $viewOrTitleOverride);
    }

    public function renderFile(string $filePath, ?string $titleOverride = null): Response
    {
        $parser = new PhueTemplateParser();
        $component = $parser->parse($filePath);

        $name = pathinfo($filePath, PATHINFO_FILENAME);
        $title = $titleOverride ?? $component->title;

        return $this->renderComponentAsModule($name, $title, $component->view);
    }

    /**
     * Renders a component using Module Mode (External JS asset with content-hash URL).
     */
    private function renderComponentAsModule(string $name, string $title, View $view): Response
    {
        $inlineScript = $view->getInlineScript();
        
        if ($inlineScript !== null && trim($inlineScript) !== '') {
            $hash = substr(sha1($inlineScript), 0, 8);
            $scriptUrl = "/assets/phue/{$name}.{$hash}.phue.js";
            
            // Enqueue external module script and clear inline script from shell
            $this->assetRegistry->addScript($scriptUrl, isModule: true);
            
            $moduleView = new View(
                template: $view->getTemplate(),
                state: $view->getState(),
                scripts: $view->getScripts(),
                inlineScript: null
            );

            return $this->renderView($title, $moduleView);
        }

        return $this->renderView($title, $view);
    }

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