<?php

declare(strict_types=1);

namespace Phue\Core\UI;

/**
 * Encapsulates view template data, initial client state payload, and JavaScript asset dependencies.
 *
 * @package Phue\Core\UI
 */
class View
{
    /**
     * View constructor.
     *
     * @param string $template In-DOM HTML markup for hydration.
     * @param array<string, mixed> $state Initial state injected into window.__INITIAL_STATE__.
     * @param array<int, string> $scripts External ESM script URLs.
     * @param string|null $inlineScript Inline ESM JavaScript logic extracted from <script> block.
     */
    public function __construct(
        private string $template,
        private array $state = [],
        private array $scripts = [],
        private ?string $inlineScript = null
    ) {}

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function getScripts(): array
    {
        return $this->scripts;
    }

    public function getInlineScript(): ?string
    {
        return $this->inlineScript;
    }
}