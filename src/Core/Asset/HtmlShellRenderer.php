<?php

declare(strict_types=1);

namespace Phue\Core\Asset;

use Phue\Core\Console\PathResolver;
use JsonException;

/**
 * Renders the HTML document shell wrapping page content, styles, and scripts.
 */
class HtmlShellRenderer
{
    private ImportMapManager $importMapManager;

    /**
     * @param AssetRegistry $registry The asset registry containing assets to load.
     * @param ImportMapManager|null $importMapManager The import map manager for import maps and CSS links.
     */
    public function __construct(
        private AssetRegistry $registry,
        ?ImportMapManager $importMapManager = null
    ) {
        $this->importMapManager = $importMapManager ?? new ImportMapManager(PathResolver::resolveBasePath());
    }

    /**
     * Renders a complete HTML document shell.
     *
     * @param string $title Page title.
     * @param string $appId DOM element ID for application root (default: 'app').
     * @param array $initialState Initial state object to inject into window.__INITIAL_STATE__.
     * @param string $content Inner HTML content.
     * @param string|null $inlineScript Optional inline module script.
     * @param bool $wrapInAppContainer Wrap content in div#app (default: true).
     * @param string|null $headScript Script to inject at the top of <head>.
     * @param array $navState State object for PhNavigation injected into window.__NAV_STATE__.
     * @return string Complete HTML document markup.
     */
    public function render(
        string $title = "Phue App",
        string $appId = "app",
        array $initialState = [],
        string $content = "",
        ?string $inlineScript = null,
        bool $wrapInAppContainer = true,
        ?string $headScript = null,
        array $navState = []
    ): string {
        $safeTitle = $this->escape($title);
        $safeAppId = $this->escape($appId);

        $headScriptMarkup =
            $headScript !== null && trim($headScript) !== ""
                ? "<script>\n{$headScript}\n</script>\n    "
                : "";

        $stylesMarkup = implode(
            "\n    ",
            array_map(
                fn(string $url) => sprintf(
                    '<link rel="stylesheet" href="%s">',
                    $this->escape($url)
                ),
                $this->registry->getStyles()
            )
        );

        $manifestStylesheetLinks = $this->importMapManager->renderStylesheetLinks();
        $combinedStyles = trim($manifestStylesheetLinks) !== ''
            ? ($stylesMarkup !== '' ? $manifestStylesheetLinks . "\n    " . $stylesMarkup : $manifestStylesheetLinks)
            : $stylesMarkup;

        $importMapMarkup = $this->importMapManager->renderImportMapTag();

        $scriptsMarkup = implode(
            "\n    ",
            array_map(
                function (array $script): string {
                    $safeUrl = $this->escape($script['url']);
                    $typeAttr = $script['isModule'] ? ' type="module"' : '';
                    return sprintf('<script%s src="%s"></script>', $typeAttr, $safeUrl);
                },
                $this->registry->getScripts()
            )
        );

        $stateMarkup = "";
        if (!empty($initialState)) {
            try {
                $jsonState = json_encode(
                    $initialState,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                $jsonState = "{}";
            }
            $stateMarkup = "<script>window.__INITIAL_STATE__ = {$jsonState};</script>\n    ";
        }

        $navStateMarkup = "";
        if (!empty($navState)) {
            try {
                $jsonNavState = json_encode(
                    $navState,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                $jsonNavState = "{}";
            }
            $navStateMarkup = "<script>window.__NAV_STATE__ = {$jsonNavState};</script>\n    ";
        }

        $inlineScriptMarkup =
            $inlineScript !== null && $inlineScript !== ""
                ? "<script type=\"module\">\n{$inlineScript}\n</script>\n    "
                : "";

        $bodyContent = $wrapInAppContainer
            ? "<div id=\"{$safeAppId}\">{$content}</div>"
            : $content;

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$safeTitle}</title>
            {$headScriptMarkup}{$combinedStyles}
        </head>
        <body>
            {$bodyContent}
            {$importMapMarkup}
            {$stateMarkup}{$navStateMarkup}{$scriptsMarkup}{$inlineScriptMarkup}</body>
        </html>
        HTML;
    }

    /**
     * Escapes a string for safe output in HTML.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }
}