<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Exception;
use RuntimeException;

/**
 * Class PhueComponentLoader
 *
 * Loads, parses, and evaluates single-file Phue components (.phue).
 *
 * @package Phue\Core\UI
 */
class PhueComponentLoader
{
    private string $componentsDir;

    /**
     * Caches ONLY static raw structure per file path, NEVER the evaluated state.
     *
     * @var array<string, array{mtime: int, size: int, structure: array{template: string, script: ?string, phpCode: ?string}}>
     */
    private array $cache = [];

    private PhueTemplateParser $parser;

    public function __construct(string $componentsDir, ?PhueTemplateParser $parser = null)
    {
        $realBase = realpath($componentsDir);
        if ($realBase === false || !is_dir($realBase)) {
            throw new RuntimeException("Invalid component directory path: {$componentsDir}");
        }

        $this->componentsDir = $realBase;
        $this->parser = $parser ?? new PhueTemplateParser();
    }

    /**
     * Loads, parses (or fetches cached structure), and dynamically evaluates PHP block with $params.
     *
     * @param string               $name   Component logical name.
     * @param array<string, mixed> $params Parameters passed into the <php> evaluation context.
     *
     * @return PhueComponent
     */
    public function load(string $name, array $params = []): PhueComponent
    {
        $structure = $this->getOrFetchStructure($name);

        return $this->evaluateStructure($structure, $params);
    }

    /**
     * Retrieves the un-evaluated raw JavaScript content for a component by name.
     * Performs zero PHP evaluation to ensure no side effects or variable leaks occur.
     *
     * @param string $name Component logical name.
     *
     * @return string|null Inline JavaScript content or null if not found/has no script.
     */
    public function contentFor(string $name): ?string
    {
        try {
            $structure = $this->getOrFetchStructure($name);
            return $structure['script'] ?? null;
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Retrieves the inline script content for a component IF the hash matches.
     *
     * @param string $name Component logical name.
     * @param string $hash 8-character sha1 prefix hash.
     *
     * @return string|null JavaScript content or null if unknown, malformed, or hash mismatch.
     */
    public function scriptFor(string $name, string $hash): ?string
    {
        $script = $this->contentFor($name);
        if ($script === null) {
            return null;
        }

        $computedHash = substr(sha1($script), 0, 8);
        if (!hash_equals($computedHash, $hash)) {
            return null;
        }

        return $script;
    }

    /**
     * Evaluates parsed structure dynamically per-render call.
     *
     * @param array{template: string, script: ?string, phpCode: ?string} $structure
     * @param array<string, mixed>                                       $params
     *
     * @return PhueComponent
     */
    private function evaluateStructure(array $structure, array $params): PhueComponent
    {
        $meta = $this->parser->evaluatePhpBlock($structure['phpCode'] ?? '', $params);

        $title = (string) ($meta['title'] ?? 'Phue App');
        $state = is_array($meta['state'] ?? null) ? $meta['state'] : [];
        $scripts = is_array($meta['scripts'] ?? null) ? $meta['scripts'] : [];

        $view = new View(
            template: $structure['template'],
            state: $state,
            scripts: $scripts,
            inlineScript: $structure['script']
        );

        return new PhueComponent($title, $view);
    }

    /**
     * Resolves file path and returns/caches ONLY the static AST structure.
     *
     * @param string $name
     *
     * @return array{template: string, script: ?string, phpCode: ?string}
     */
    private function getOrFetchStructure(string $name): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name)) {
            throw new RuntimeException("Invalid component name format: '{$name}'");
        }

        $targetPath = $this->componentsDir . DIRECTORY_SEPARATOR . $name . '.phue';
        $resolvedPath = realpath($targetPath);

        if ($resolvedPath === false || !str_starts_with($resolvedPath, $this->componentsDir . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Phue component file not found or inaccessible: '{$name}'");
        }

        $stat = stat($resolvedPath);
        if ($stat === false) {
            throw new RuntimeException("Failed to stat component file: {$resolvedPath}");
        }

        $mtime = $stat['mtime'];
        $size = $stat['size'];

        // Static Structural Cache Hit
        if (isset($this->cache[$resolvedPath])) {
            $cached = $this->cache[$resolvedPath];
            if ($cached['mtime'] === $mtime && $cached['size'] === $size) {
                return $cached['structure'];
            }
        }

        // Cache Miss
        $structure = $this->parser->parseStructure($resolvedPath);

        $this->cache[$resolvedPath] = [
            'mtime'     => $mtime,
            'size'      => $size,
            'structure' => $structure,
        ];

        return $structure;
    }
}