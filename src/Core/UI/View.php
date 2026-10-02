<?php

declare(strict_types=1);

namespace Phue\Core\UI;

/**
 * Encapsulates view template data, initial client state payload, JavaScript asset dependencies,
 * component-scoped CSS styles, and optional server-rendered HTML output for HTML-first primitive execution.
 *
 * @package Phue\Core\UI
 */
class View
{
    /**
     * View constructor.
     *
     * @param string $template              In-DOM HTML markup reserved for Vue hydration.
     * @param array<string, mixed> $state   Initial state payload injected into window.__INITIAL_STATE__.
     * @param array<int, string> $scripts   External ESM script URLs to be enqueued.
     * @param string|null $inlineScript     Inline ESM JavaScript logic extracted from the <script> block.
     * @param string|null $html             Fully resolved server-rendered HTML markup (HTML-first / SSR mode).
     * @param array<int, string> $styles    External CSS style URLs to be enqueued.
     * @param string|null $style            Component-scoped CSS string extracted from the <style> block.
     * @param bool $isVueTemplate           Discriminator flag indicating whether $template contains Vue directives/syntax.
     */
    public function __construct(
        private string $template,
        private array $state = [],
        private array $scripts = [],
        private ?string $inlineScript = null,
        private ?string $html = null,
        private array $styles = [],
        private ?string $style = null,
        private bool $isVueTemplate = false
    ) {}

    /**
     * Retrieves the raw static in-DOM template string.
     *
     * @return string Static HTML template string for Vue client mounting.
     */
    public function getTemplate(): string
    {
        return $this->template;
    }

    /**
     * Indicates whether the template contains Vue directives/syntax and must not be dumped as raw static markup.
     *
     * @return bool True if template contains Vue directives (e.g., v-if, {{ }}), false if pure static HTML.
     */
    public function isVueTemplate(): bool
    {
        return $this->isVueTemplate;
    }

    /**
     * Retrieves the initial client state array.
     *
     * @return array<string, mixed> Associative array of state variables.
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * Retrieves external script dependency URLs.
     *
     * @return array<int, string> List of script URLs.
     */
    public function getScripts(): array
    {
        return $this->scripts;
    }

    /**
     * Retrieves external styles dependency URLs.
     *
     * @return array<int, string> List of style URLs.
     */
    public function getStyles(): array
    {
        return $this->styles;
    }

    /**
     * Retrieves the component-scoped raw CSS style string.
     *
     * @return string|null CSS style string or null if absent.
     */
    public function getStyle(): ?string
    {
        return $this->style;
    }

    /**
     * Determines whether the view contains an inline scoped CSS style block.
     *
     * @return bool True if a style block exists and is non-empty, false otherwise.
     */
    public function hasStyle(): bool
    {
        return $this->style !== null && trim($this->style) !== '';
    }

    /**
     * Retrieves extracted inline script content.
     *
     * @return string|null JavaScript code string or null if absent.
     */
    public function getInlineScript(): ?string
    {
        return $this->inlineScript;
    }

    /**
     * Retrieves the fully resolved server-rendered HTML markup.
     *
     * @return string|null Resolved HTML string or null if running in template mode.
     */
    public function getHtml(): ?string
    {
        return $this->html;
    }

    /**
     * Determines whether the view contains pre-rendered HTML (HTML-first mode).
     *
     * @return bool True if resolved HTML is provided, false otherwise.
     */
    public function hasHtml(): bool
    {
        return $this->html !== null;
    }
}