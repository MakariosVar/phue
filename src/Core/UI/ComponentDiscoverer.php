<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Psr\Log\LoggerInterface;

/**
 * Scans templates to discover and resolve known UI components using ComponentLoaderRegistry.
 *
 * @package Phue\Core\UI
 */
class ComponentDiscoverer
{
    /**
     * @var ComponentLoaderRegistry
     */
    private ComponentLoaderRegistry $registry;

    /**
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * List of Vue built-in tags to ignore during scanning.
     *
     * @var array<int, string>
     */
    private const VUE_BUILTINS = [
        'component',
        'slot',
        'template',
        'transition',
        'transition-group',
        'keep-alive',
        'teleport',
        'suspense',
    ];

    /**
     * List of native HTML tags to ignore during scanning.
     *
     * @var array<int, string>
     */
    private const NATIVE_HTML_TAGS = [
        'a', 'abbr', 'address', 'area', 'article', 'aside', 'audio', 'b', 'base',
        'bdi', 'bdo', 'blockquote', 'body', 'br', 'button', 'canvas', 'caption',
        'cite', 'code', 'col', 'colgroup', 'data', 'datalist', 'dd', 'del',
        'details', 'dfn', 'dialog', 'div', 'dl', 'dt', 'em', 'embed', 'fieldset',
        'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'head', 'header', 'hgroup', 'hr', 'html', 'i', 'iframe', 'img',
        'input', 'ins', 'kbd', 'label', 'legend', 'li', 'link', 'main', 'map',
        'mark', 'menu', 'meta', 'meter', 'nav', 'noscript', 'object', 'ol',
        'optgroup', 'option', 'output', 'p', 'param', 'picture', 'pre', 'progress',
        'q', 'rp', 'rt', 'ruby', 's', 'samp', 'script', 'section', 'select',
        'small', 'source', 'span', 'strong', 'style', 'sub', 'summary', 'sup',
        'svg', 'table', 'tbody', 'td', 'template', 'textarea', 'tfoot', 'th',
        'thead', 'time', 'title', 'tr', 'track', 'u', 'ul', 'var', 'video', 'wbr',
    ];

    /**
     * ComponentDiscoverer constructor.
     *
     * @param ComponentLoaderRegistry $registry
     * @param LoggerInterface|null    $logger
     */
    public function __construct(ComponentLoaderRegistry $registry, ?LoggerInterface $logger = null)
    {
        $this->registry = $registry;
        $this->logger = $logger;
    }

    /**
     * Scans a template string and returns a deduplicated, sorted array of PascalCase
     * names for known components present within the registry.
     *
     * @param string $template The raw template content to scan.
     * @param string|null $self The name of the component scanning itself, if applicable.
     *
     * @return array<int, string> Deduplicated and sorted list of PascalCase component names.
     */
    public function scanTemplate(string $template, ?string $self = null): array
    {
        $found = [];

        if (preg_match_all('/<([A-Za-z][A-Za-z0-9\-]*)/', $template, $matches)) {
            foreach ($matches[1] as $tag) {
                $lowerTag = strtolower($tag);

                if (in_array($lowerTag, self::NATIVE_HTML_TAGS, true) || in_array($lowerTag, self::VUE_BUILTINS, true)) {
                    continue;
                }

                $pascalName = $this->normalizeToPascalCase($tag);

                // A self-reference is not a dependency. It is already in this
                // module, so importing it would be a self-import.
                if ($self !== null && $pascalName === $self) {
                    continue;
                }

                // Use cheap existence check instead of triggering module build via moduleUrl/scriptUrl
                if ($this->registry->hasComponent($pascalName)) {
                    $found[$pascalName] = true;
                }
            }
        }

        $result = array_keys($found);
        sort($result);

        return $result;
    }

    /**
     * Recursively resolves transitive component dependencies for the SSR envelope (Phase 3).
     *
     * @param string               $name    Component logical name.
     * @param array<string, bool>  $visited Track visited components during traversal.
     * @param array<string, string> $graph  Accumulated dependency graph (Name => Hash).
     *
     * @return array<string, string> Map of component name => fullHash for the dependency graph.
     */
    public function resolveTransitiveDependencies(string $name, array &$visited = [], array &$graph = []): array
    {
        // Prevent infinite loops on cyclic dependencies
        if (isset($visited[$name])) {
            return $graph;
        }

        $visited[$name] = true;

        $moduleUrl = $this->registry->moduleUrl($name);

        if ($moduleUrl !== null) {
            // Extract asset hash when moduleUrl is present
            if (preg_match('/\.([a-f0-9]{8,40})\.phue\.js$/i', $moduleUrl, $matches)) {
                $graph[$name] = $matches[1];
            }
        } else {
            // Log warning when moduleUrl is null instead of exiting immediately
            $this->logger?->warning("Unable to resolve module URL for component during dependency resolution", [
                'component' => $name,
            ]);
        }

        $component = $this->registry->load($name);
        if ($component === null) {
            return $graph;
        }

        $template = $component->view->getTemplate();
        if (empty($template)) {
            return $graph;
        }

        $children = $this->scanTemplate($template, $name);

        foreach ($children as $childName) {
            // Pass shared $visited and $graph references down the stack
            $this->resolveTransitiveDependencies($childName, $visited, $graph);
        }

        return $graph;
    }

    /**
     * Normalizes tag name to PascalCase.
     *
     * @param string $tag
     *
     * @return string
     */
    private function normalizeToPascalCase(string $tag): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $tag)));
    }
}