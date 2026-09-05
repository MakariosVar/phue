<?php

declare(strict_types=1);

namespace Phue\Core\UI;

/**
 * Encapsulates view template data, initial client state payload, and JavaScript asset dependencies.
 *
 * Designed for server-authoritative rendering where HTML markup is inlined
 * directly into the initial payload for front-end framework hydration (e.g. Vue.js).
 *
 * @package Phue\Core\UI
 */
class View
{
    /**
     * View constructor.
     *
     * @param string $template In-DOM HTML markup to be placed inside the application mounting element.
     * @param array<string, mixed> $state Initial state array injected into the client window.__INITIAL_STATE__ object.
     * @param array<int, string> $scripts List of asset URL paths for ESM module scripts required by this view. Defaults to empty array.
     */
    public function __construct(
        private string $template,
        private array $state = [],
        private array $scripts = []
    ) {}

    /**
     * Get the HTML template markup for the view.
     *
     * @return string The raw HTML template string.
     */
    public function getTemplate(): string
    {
        return $this->template;
    }

    /**
     * Get the initial state payload for hydration.
     *
     * @return array<string, mixed> Key-value pairs representing client initial state.
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * Get the list of script asset URLs required by the view.
     *
     * @return array<int, string> List of script URLs.
     */
    public function getScripts(): array
    {
        return $this->scripts;
    }
}