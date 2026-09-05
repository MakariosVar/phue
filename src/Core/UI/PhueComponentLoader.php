<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use RuntimeException;

/**
 * Resolves, validates, and caches Phue single file components by logical name.
 *
 * @package Phue\Core\UI
 */
class PhueComponentLoader
{
    private string $componentsDir;

    /**
     * @var array<string, array{mtime: int, size: int, component: PhueComponent}>
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
     * Loads a PhueComponent by logical name with path-traversal guarding and mtime/size caching.
     *
     * @throws RuntimeException On invalid component names, path traversal attempts, or missing files.
     */
    public function load(string $name): PhueComponent
    {
        // Name Guard Validation: Allow alphanumeric, underscores, hyphens, non-leading
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name)) {
            throw new RuntimeException("Invalid component name format: '{$name}'");
        }

        $targetPath = $this->componentsDir . DIRECTORY_SEPARATOR . $name . '.phue';
        $resolvedPath = realpath($targetPath);

        // Path Traversal Security Verification: Must exist and remain within base directory
        if ($resolvedPath === false || !str_starts_with($resolvedPath, $this->componentsDir . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Phue component file not found or inaccessible: '{$name}'");
        }

        $stat = stat($resolvedPath);
        if ($stat === false) {
            throw new RuntimeException("Failed to stat component file: {$resolvedPath}");
        }

        $mtime = $stat['mtime'];
        $size = $stat['size'];

        // Cache Check & Invalidation (re-parse if mtime or size changed)
        if (isset($this->cache[$resolvedPath])) {
            $cached = $this->cache[$resolvedPath];
            if ($cached['mtime'] === $mtime && $cached['size'] === $size) {
                return $cached['component'];
            }
        }

        $component = $this->parser->parse($resolvedPath);

        $this->cache[$resolvedPath] = [
            'mtime'     => $mtime,
            'size'      => $size,
            'component' => $component,
        ];

        return $component;
    }
}