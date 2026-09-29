<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use App\Config\NavigationConfig;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Response;
use Phue\Core\Ssr\SsrEngine;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Renders Phue components into HTTP responses.
 */
class ComponentRenderer
{
    /**
     * Track rendered component styles to deduplicate per request.
     *
     * @var array<string, bool>
     */
    private array $renderedStyles = [];

    /**
     * @param HtmlShellRenderer $shellRenderer The HTML shell renderer.
     * @param AssetRegistry $assetRegistry The asset registry.
     * @param PhueComponentLoader|null $loader The component loader.
     * @param string|null $layoutName Optional layout name to wrap components.
     * @param callable|null $uriResolver Optional callable returning current request URI string.
     * @param callable|null $requestResolver Optional callable returning current Request object.
     * @param PhuePartialRenderer|null $partialRenderer Optional partial renderer.
     * @param callable|null $userResolver Optional callable returning authenticated user.
     * @param SsrEngine|null $ssrEngine Optional Vue SSR engine.
     * @param ComponentLoaderRegistry|null $loaderRegistry Optional loader registry.
     * @param array $themeConfig Theme configuration options.
     * @param array $metaConfig Meta tags configuration options.
     * @param LoggerInterface|null $logger Optional PSR-3 logger instance.
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
        private ?ComponentLoaderRegistry $loaderRegistry = null,
        private array $themeConfig = [],
        private array $metaConfig = [],
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Renders a component by name or title, returning a Response.
     *
     * @param string $nameOrTitle Component name or page title.
     * @param View|string|array|null $viewOrTitleOrParams View instance, page title, or parameters array.
     * @param array $params Data parameters if first argument is component name.
     * @return Response
     * @throws RuntimeException If loader is required but not configured.
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
        return $this->renderView($nameOrTitle, $viewOrTitleOrParams, $nameOrTitle);
    }

    /**
     * Renders a .phue template file directly.
     *
     * @param string $filePath Full path to the .phue file.
     * @param string|null $titleOverride Optional title to override template metadata.
     * @param array $params Parameters for template execution.
     * @return Response
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
     * Handles module-based inline scripts, registering them as dynamic assets.
     */
    private function renderComponentAsModule(string $name, string $title, View $view): Response
    {
        $inlineScript = $view->getInlineScript();

        if ($inlineScript !== null && trim($inlineScript) !== '') {
            // Step 1: Mint URL via loaderRegistry or fallback to raw generation
            $scriptUrl = $this->loaderRegistry?->moduleUrl($name);

            if ($scriptUrl === null) {
                $hash = $this->loader?->moduleHash($name);
                if ($hash !== null) {
                    $scriptUrl = "/assets/phue/{$name}.{$hash}.phue.js";
                }
            }

            if ($scriptUrl !== null) {
                $this->assetRegistry->addScript($scriptUrl, isModule: true);
            } else {
                $this->logger?->error("Failed to generate module URL/hash for component hydration script", [
                    'component' => $name,
                ]);
            }

            $moduleView = new View(
                template: $view->getTemplate(),
                state: $view->getState(),
                scripts: $view->getScripts(),
                inlineScript: null,
                html: $view->getHtml(),
                styles: $view->getStyles(),
                style: $view->getStyle()
            );

            return $this->renderView($title, $moduleView, $name);
        }

        return $this->renderView($title, $view, $name);
    }

    /**
     * Builds and returns the final HTML response.
     */
    private function renderView(string $title, View $view, ?string $componentName = null): Response
    {
        $uri = is_callable($this->uriResolver) ? ($this->uriResolver)() : '/';

        // 1a: Compute transitive dependency graph upfront for both SSR and Client modulepreloads
        $deps = [];
        if ($this->loaderRegistry !== null && $componentName !== null) {
            $discoverer = new ComponentDiscoverer($this->loaderRegistry);
            $deps = $discoverer->resolveTransitiveDependencies($componentName);
        }

        if (!$view->hasHtml() && $this->ssrEngine !== null) {
            $state = $view->getState();
            $context = ['name' => $title, 'uri' => $uri];

            if (!empty($deps)) {
                $components = [];

                foreach ($deps as $depName => $depHash) {
                    if ($depName === $componentName) {
                        continue;
                    }

                    // Query SSR module via loaderRegistry
                    $source = method_exists($this->loaderRegistry, 'buildForSsr') 
                        ? $this->loaderRegistry->buildForSsr($depName) 
                        : $this->loaderRegistry->moduleFor($depName, $depHash);

                    if ($source !== null && trim($source) !== '') {
                        $components[$depName] = trim($source);
                    }
                }

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
                    html: $renderedHtml,
                    styles: $view->getStyles(),
                    style: $view->getStyle()
                );
            }
        }

        foreach ($view->getScripts() as $script) {
            $this->assetRegistry->addScript($script, isModule: true);
        }

        foreach ($view->getStyles() as $style) {
            $this->assetRegistry->addRequestStyle($style);
        }

        $styleTag = '';
        if ($view->hasStyle() && $componentName !== null && !isset($this->renderedStyles[$componentName])) {
            $this->renderedStyles[$componentName] = true;
            $scopeAttr = 'data-phue-scope="' . htmlspecialchars($componentName, ENT_QUOTES, 'UTF-8') . '"';
            $styleTag = "<style {$scopeAttr}>" . $view->getStyle() . "</style>";
        }

        $rawMarkup = $view->hasHtml() ? $view->getHtml() : $view->getTemplate();
        $pageMarkup = $styleTag . $rawMarkup;

        $request = is_callable($this->requestResolver) ? ($this->requestResolver)() : null;
        $isSpaRequest = $request !== null && $request->getHeader('X-Phue-SPA') === 'true';

        $user = is_callable($this->userResolver) ? ($this->userResolver)() : null;
        $navState = [
            'items'       => class_exists(NavigationConfig::class) ? NavigationConfig::getAppItems($user) : [],
            'actions'     => class_exists(NavigationConfig::class) ? NavigationConfig::getAppActions($user) : [],
            'activeRoute' => $uri,
            'orientation' => class_exists(NavigationConfig::class) ? NavigationConfig::getAppPlacement() : 'horizontal',
            'themeConfig' => $this->themeConfig
        ];

        // 1β: Generate modulepreload URLs for the full dependency graph
        $modulePreloads = [];
        if ($this->loaderRegistry !== null && !empty($deps)) {
            foreach (array_keys($deps) as $depName) {
                $url = $this->loaderRegistry->moduleUrl($depName);
                if ($url !== null) {
                    $modulePreloads[] = $url;
                }
            }
        }

        if ($isSpaRequest) {
            $requestScripts = $this->assetRegistry->getRequestScripts();
            $flatScriptUrls = array_map(
                static fn(mixed $script) => is_array($script) ? ($script['url'] ?? '') : (string) $script,
                $requestScripts
            );

            return Response::json([
                'title'    => $title,
                'html'     => $pageMarkup,
                'state'    => $view->getState(),
                'scripts'  => array_values(array_filter($flatScriptUrls)),
                'navState' => $navState,
                'modulePreload' => $modulePreloads
            ]);
        }

        $mountedPageContent = '<div id="app">' . $pageMarkup . '</div>';
        $appContent = $this->applyLayoutIfConfigured($mountedPageContent);

        $storageKey = $this->themeConfig['storageKey'] ?? 'phue-theme';
        $defaultTheme = $this->themeConfig['defaultTheme'] ?? 'system';

        $themeInitScript = sprintf(
            <<<'JS'
(function() {
    var saved = localStorage.getItem('%s');
    var theme = saved ? saved : ('%s' === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : '%s');
    document.documentElement.setAttribute('data-bs-theme', theme);
})();
JS,
            addslashes($storageKey),
            addslashes($defaultTheme),
            addslashes($defaultTheme)
        );

        $html = $this->shellRenderer->render(
            title: $title,
            appId: 'app',
            initialState: $view->getState(),
            content: $appContent,
            inlineScript: $view->getInlineScript(),
            wrapInAppContainer: false,
            headScript: $themeInitScript,
            navState: $navState,
            metaDescription: $this->metaConfig['metaDescription'] ?? null,
            modulePreloads: $modulePreloads
        );

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Applies configured layout template around page markup.
     */
    private function applyLayoutIfConfigured(string $pageMarkup, string $navHtml = ''): string
    {
        if ($this->layoutName === null) {
            return $pageMarkup;
        }

        $loader = $this->loaderRegistry ?? $this->loader;
        if ($loader === null) {
            return $pageMarkup;
        }

        $uri = is_callable($this->uriResolver) ? ($this->uriResolver)() : '/';
        $user = is_callable($this->userResolver) ? ($this->userResolver)() : null;

        // Load navigation conditionally if requested by layout and loader supports it
        $hasNavComponent = method_exists($loader, 'hasComponent') 
            ? $loader->hasComponent('PhNavigation') 
            : true;

        if ($navHtml === '' && $hasNavComponent && class_exists(NavigationConfig::class)) {
            $navState = [
                'items'       => NavigationConfig::getAppItems($user),
                'actions'     => NavigationConfig::getAppActions($user),
                'activeRoute' => $uri,
                'orientation' => NavigationConfig::getAppPlacement(),
                'themeConfig' => $this->themeConfig,    
            ];

            try {
                $navComponent = $loader->load('PhNavigation', $navState);

                if ($this->ssrEngine !== null) {
                    $context = ['name' => 'PhNavigation', 'uri' => $uri];

                    if ($this->loaderRegistry !== null) {
                        $discoverer = new ComponentDiscoverer($this->loaderRegistry);
                        $deps = $discoverer->resolveTransitiveDependencies('PhNavigation');
                        $components = [];

                        // Include PhNavigation itself as root entry in components map
                        $navSource = method_exists($this->loaderRegistry, 'buildForSsr') 
                            ? $this->loaderRegistry->buildForSsr('PhNavigation') 
                            : null;

                        if ($navSource !== null && trim($navSource) !== '') {
                            $components['PhNavigation'] = trim($navSource);
                        } else {
                            $navHash = $this->loaderRegistry->moduleHash('PhNavigation');
                            if ($navHash !== null) {
                                $fallbackNavSource = $this->loaderRegistry->moduleFor('PhNavigation', $navHash);
                                if ($fallbackNavSource !== null && trim($fallbackNavSource) !== '') {
                                    $components['PhNavigation'] = trim($fallbackNavSource);
                                }
                            }
                        }

                        // Include transitive dependencies
                        foreach ($deps as $depName => $depHash) {
                            if ($depName === 'PhNavigation') {
                                continue;
                            }

                            $source = method_exists($this->loaderRegistry, 'buildForSsr') 
                                ? $this->loaderRegistry->buildForSsr($depName) 
                                : $this->loaderRegistry->moduleFor($depName, $depHash);

                            if ($source !== null && trim($source) !== '') {
                                $components[$depName] = trim($source);
                            }
                        }

                        if (!empty($components)) {
                            $context['components'] = $components;
                        }
                    }

                    $navHtml = $this->ssrEngine->render(
                        template: $navComponent->view->getTemplate(),
                        state: $navState,
                        context: $context
                    ) ?? '';
                }

                $navScriptUrl = $this->loaderRegistry?->moduleUrl('PhNavigation');
                if ($navScriptUrl !== null) {
                    $this->assetRegistry->addScript($navScriptUrl, isModule: true);
                }
            } catch (Throwable $e) {
                // Log warning before falling back gracefully if PhNavigation template is absent or rendering fails
                $this->logger?->warning('Failed to render PhNavigation component in ComponentRenderer', [
                    'exception' => $e,
                    'uri' => $uri,
                ]);
                $navHtml = '';
            }
        }

        $layoutComponent = $loader->load($this->layoutName, [
            'content'         => $pageMarkup,
            'navHtml'         => $navHtml,
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