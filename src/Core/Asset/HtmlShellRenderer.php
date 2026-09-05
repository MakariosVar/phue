<?php

declare(strict_types=1);

namespace Phue\Core\Asset;

use JsonException;

/**
 * Renders the baseline HTML5 shell payload with registered asset dependencies and inline scripts.
 *
 * @package Phue\Core\Asset
 */
class HtmlShellRenderer
{
    public function __construct(
        private AssetRegistry $registry
    ) {}

    /**
     * Render the full HTML document string.
     *
     * @param string $title Page title
     * @param string $appId Mounting root element ID (default: "app")
     * @param array<string, mixed> $initialState Global state injected via `window.__INITIAL_STATE__`
     * @param string $content Inner HTML content
     * @param string|null $inlineScript Inline module script block
     *
     * @return string Valid HTML5 document markup
     */
    public function render(
        string $title = 'Phue App',
        string $appId = 'app',
        array $initialState = [],
        string $content = '',
        ?string $inlineScript = null
    ): string {
        $safeTitle = $this->escape($title);
        $safeAppId = $this->escape($appId);

        $stylesMarkup = implode("\n    ", array_map(
            fn(string $url) => sprintf('<link rel="stylesheet" href="%s">', $this->escape($url)),
            $this->registry->getStyles()
        ));

        $scriptsMarkup = implode("\n    ", array_map(
            function (array $script): string {
                $safeUrl = $this->escape($script['url']);
                $typeAttr = $script['isModule'] ? ' type="module"' : '';
                return sprintf('<script%s src="%s"></script>', $typeAttr, $safeUrl);
            },
            $this->registry->getScripts()
        ));

        $stateMarkup = '';
        if (!empty($initialState)) {
            try {
                $jsonState = json_encode($initialState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $jsonState = '{}';
            }
            $stateMarkup = "<script>window.__INITIAL_STATE__ = {$jsonState};</script>\n    ";
        }

        $inlineScriptMarkup = $inlineScript !== null && $inlineScript !== ''
            ? "<script type=\"module\">\n{$inlineScript}\n</script>\n    "
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeTitle}</title>
    {$stylesMarkup}
</head>
<body>
    <div id="{$safeAppId}">{$content}</div>
    {$stateMarkup}{$scriptsMarkup}{$inlineScriptMarkup}</body>
</html>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}