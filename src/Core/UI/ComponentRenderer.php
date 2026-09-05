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

    /**
     * Overloads:
     * - render('Products')
     * - render('Products', ['testValue' => 'a'])
     * - render('Products', 'Title Override', ['testValue' => 'a'])
     * - render('Title', $viewInstance)
     */
    public function render(
        string $nameOrTitle,
        View|string|array|null $viewOrTitleOrParams = null,
        array $params = []
    ): Response {
        // Path 1: render('Products', ['testValue' => 'a'])
        if (is_array($viewOrTitleOrParams)) {
            if ($this->loader === null) {
                throw new RuntimeException('Cannot render component by name: PhueComponentLoader is not configured.');
            }

            $component = $this->loader->load($nameOrTitle, $viewOrTitleOrParams);
            return $this->renderComponentAsModule($nameOrTitle, $component->title, $component->view);
        }

        // Path 2: render('Products') OR render('Products', 'Title Override', ['testValue' => 'a'])
        if ($viewOrTitleOrParams === null || is_string($viewOrTitleOrParams)) {
            if ($this->loader === null) {
                throw new RuntimeException('Cannot render component by name: PhueComponentLoader is not configured.');
            }

            $component = $this->loader->load($nameOrTitle, $params);
            $title = $viewOrTitleOrParams ?? $component->title;

            return $this->renderComponentAsModule($nameOrTitle, $title, $component->view);
        }

        // Path 3: Direct BC Path -> render('Title', $viewInstance)
        return $this->renderView($nameOrTitle, $viewOrTitleOrParams);
    }

    public function renderFile(string $filePath, ?string $titleOverride = null, array $params = []): Response
    {
        $parser = new PhueTemplateParser();
        $component = $parser->parse($filePath, $params);

        $name = pathinfo($filePath, PATHINFO_FILENAME);
        $title = $titleOverride ?? $component->title;

        return $this->renderComponentAsModule($name, $title, $component->view);
    }

    private function renderComponentAsModule(string $name, string $title, View $view): Response
    {
        $inlineScript = $view->getInlineScript();

        if ($inlineScript !== null && trim($inlineScript) !== '') {
            $hash = substr(sha1($inlineScript), 0, 8);
            $scriptUrl = "/assets/phue/{$name}.{$hash}.phue.js";

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