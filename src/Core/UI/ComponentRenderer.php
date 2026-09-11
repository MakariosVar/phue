<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Response;
use Phue\Core\Ssr\SsrEngine;
use RuntimeException;

/**
 * Class ComponentRenderer
 *
 * Handles the rendering lifecycle of Phue components, layouts, and Views.
 * Supports HTML-first server layouts and template-based client hydration.
 *
 * @package Phue\Core\UI
 */
class ComponentRenderer
{
    /**
     * ComponentRenderer constructor.
     *
     * @param HtmlShellRenderer            $shellRenderer   Service responsible for rendering the outer HTML document shell.
     * @param AssetRegistry                $assetRegistry   Global registry managing script dependencies.
     * @param PhueComponentLoader|null     $loader          Optional component loader instance.
     * @param string|null                  $layoutName      Optional layout component name (e.g., 'AppLayout').
     * @param mixed                        $uriResolver     Optional callable returning current request URI string: fn(): string.
     * @param mixed                        $requestResolver Optional callable returning current Request object.
     * @param PhuePartialRenderer|null     $partialRenderer Optional partial renderer for layout integration.
     * @param mixed                        $userResolver    Optional callable returning IdentityInterface|null: fn(): ?IdentityInterface.
     * @param SsrEngine|null               $ssrEngine       Optional SSR engine for server-side component evaluation.
     * @param ComponentLoaderRegistry|null $loaderRegistry  Optional registry for resolving multi-source component loaders.
     */
    public function __construct(
        private HtmlShellRenderer $shellRenderer,
        private AssetRegistry $assetRegistry,
        private ?PhueComponentLoader $loader = null,
        private ?string $layoutName = null,
        private mixed $uriResolver = null,
        private mixed $requestResolver = null,
        private ?PhuePartialRenderer $partialRenderer = null,
        private mixed $userResolver = null,
        private ?SsrEngine $ssrEngine = null,
        private ?\Phue\Core\Frontend\ComponentLoaderRegistry $loaderRegistry = null
    ) {}

    /**
     * Renders a component or View instance into an HTTP Response.
     *
     * Supported Overloads:
     * - render('Products')
     * - render('Products', ['testValue' => 'a'])
     * - render('Products', 'Title Override', ['testValue' => 'a'])
     * - render('Title', $viewInstance)
     *
     * @param string                 $nameOrTitle         Component name or page title override.
     * @param View|string|array|null $viewOrTitleOrParams View instance, title override string, or context params.
     * @param array<string, mixed>   $params              Context parameters when using title override.
     *
     * @return Response HTTP response containing the full HTML document.
     *
     * @throws RuntimeException If component loading by name is requested without a loader configured.
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

    /**
     * Renders a .phue file directly from an absolute or relative file path.
     *
     * @param string      $filePath      Path to the .phue file.
     * @param string|null $titleOverride Optional title override.
     * @param array       $params        Context parameters passed into the component evaluation.
     *
     * @return Response HTTP response containing the compiled document.
     */
    public function renderFile(string $filePath, ?string $titleOverride = null, array $params = []): Response
    {
        $parser = new PhueTemplateParser();
        $component = $parser->parse($filePath, $params);

        $name = pathinfo($filePath, PATHINFO_FILENAME);
        $title = $titleOverride ?? $component->title;

        return $this->renderComponentAsModule($name, $title, $component->view);
    }

    /**
     * Transforms inline component scripts into external module assets and delegates to renderView.
     * Keeps module generation deterministic using raw page template without layout wrapping.
     *
     * @param string $name  Component logical name.
     * @param string $title Document title.
     * @param View   $view  Evaluated View entity.
     *
     * @return Response Compiled HTTP HTML response.
     */
    private function renderComponentAsModule(string $name, string $title, View $view): Response
    {
        $inlineScript = $view->getInlineScript();

        if ($inlineScript !== null && trim($inlineScript) !== '') {
            $module = PhueComponentLoader::buildModule($view->getTemplate(), $inlineScript);
            $hash = substr(sha1($module), 0, 8);
            $scriptUrl = "/assets/phue/{$name}.{$hash}.phue.js";

            $this->assetRegistry->addScript($scriptUrl, isModule: true);

            $moduleView = new View(
                template: $view->getTemplate(),
                state: $view->getState(),
                scripts: $view->getScripts(),
                inlineScript: null,
                html: $view->getHtml()
            );

            return $this->renderView($title, $moduleView);
        }

        return $this->renderView($title, $view);
    }

    /**
     * Renders a View instance into the final HTML shell response.
     * Places the #app container strictly around the page content and wraps it in the Layout server chrome.
     *
     * @param string $title Page title.
     * @param View   $view  View object containing markup and assets.
     *
     * @return Response HTTP 200 response with text/html content type or JSON response for SPA requests.
     */
    private function renderView(string $title, View $view): Response
    {
        if (!$view->hasHtml() && $this->ssrEngine !== null) {
            $uri = is_callable($this->uriResolver) ? ($this->uriResolver)() : '/';
            $state = $view->getState();

            $context = [
                'name' => $title,
                'uri'  => $uri,
            ];

            // 1. Whitelist check: Read phuiUrls (map: name -> URL) from View state
            $phuiUrls = $state['phuiUrls'] ?? null;

            if (is_array($phuiUrls) && !empty($phuiUrls)) {
                $components = [];

                foreach ($phuiUrls as $name => $url) {
                    if (!is_string($url)) {
                        continue; // Fail-open on invalid URL types
                    }

                    // 2. Extract hash using the identical regex from AssetResolver (Application.php:254)
                    if (preg_match('/^([A-Za-z0-9_\-\/]+)\.([a-f0-9]{8})\.phue\.js$/i', basename($url), $matches)) {
                        $hash = $matches[2];

                        // Resolve module source across loaders via ComponentLoaderRegistry
                        $source = $this->loaderRegistry?->moduleFor($name, $hash);

                        // 3. Ignore null/empty (fail-open)
                        if ($source !== null && trim($source) !== '') {
                            $components[$name] = $source;
                        }
                    }
                    // Fail-open automatically if regex doesn't match
                }

                // 4. Add 'components' => $components to context ONLY if non-empty
                if (!empty($components)) {
                    $context['components'] = $components;
                }
            }

            $renderedHtml = $this->ssrEngine->render(
                template: $view->getTemplate(),
                state: $state,
                context: $context
            );

            if ($renderedHtml !== null) {
                $view = new View(
                    template: $view->getTemplate(),
                    state: $view->getState(),
                    scripts: $view->getScripts(),
                    inlineScript: $view->getInlineScript(),
                    html: $renderedHtml
                );
            }
        }

        foreach ($view->getScripts() as $script) {
            $this->assetRegistry->addScript($script, isModule: true);
        }

        $pageMarkup = $view->hasHtml() 
            ? $view->getHtml() 
            : $view->getTemplate();

        // Check if this is an SPA transition request
        $request = is_callable($this->requestResolver) ? ($this->requestResolver)() : null;
        $isSpaRequest = $request !== null && $request->getHeader('X-Phue-SPA') === 'true';

        // 1. Early Return for SPA: Return ONLY $pageMarkup without evaluating the outer Layout chrome
        if ($isSpaRequest) {
            return Response::json([
                'title'   => $title,
                'html'    => $pageMarkup,
                'state'   => $view->getState(),
                'scripts' => $this->assetRegistry->getRequestScripts(),
            ]);
        }

        // 2. Full Page Load Path: Isolate page content within #app root container for Vue hydration match
        $mountedPageContent = '<div id="app">' . $pageMarkup . '</div>';

        // 3. Wrap mounted page within server chrome layout (layout stays OUTSIDE #app)
        $appContent = $this->applyLayoutIfConfigured($mountedPageContent);

        // 4. Render full HTML shell
        $html = $this->shellRenderer->render(
            title: $title,
            appId: 'app',
            initialState: $view->getState(),
            content: $appContent,
            inlineScript: $view->getInlineScript(),
            wrapInAppContainer: false
        );

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Wraps the inner page markup within the configured Layout component if available.
     *
     * @param string $pageMarkup Evaluated page content string.
     *
     * @return string Wrapped HTML markup or original markup if no layout is defined.
     */
    private function applyLayoutIfConfigured(string $pageMarkup): string
    {
        if ($this->layoutName === null || $this->loader === null) {
            return $pageMarkup;
        }

        $uri = is_callable($this->uriResolver) ? ($this->uriResolver)() : '/';
        $user = is_callable($this->userResolver) ? ($this->userResolver)() : null;

        $layoutComponent = $this->loader->load($this->layoutName, [
            'content'         => $pageMarkup,
            'uri'             => $uri,
            'user'            => $user,
            'partialRenderer' => $this->partialRenderer,
        ]);

        $layoutView = $layoutComponent->view;

        return $layoutView->hasHtml() 
            ? $layoutView->getHtml() 
            : $layoutView->getTemplate();
    }
}