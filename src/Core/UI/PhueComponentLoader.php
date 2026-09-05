<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Exception;

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
            throw new \RuntimeException("Invalid component directory path: {$componentsDir}");
        }

        $this->componentsDir = $realBase;
        $this->parser = $parser ?? new PhueTemplateParser();
    }

    public function load(string $name): PhueComponent
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name)) {
            throw new \RuntimeException("Invalid component name format: '{$name}'");
        }

        $targetPath = $this->componentsDir . DIRECTORY_SEPARATOR . $name . '.phue';
        $resolvedPath = realpath($targetPath);

        if ($resolvedPath === false || !str_starts_with($resolvedPath, $this->componentsDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Phue component file not found or inaccessible: '{$name}'");
        }

        $stat = stat($resolvedPath);
        if ($stat === false) {
            throw new \RuntimeException("Failed to stat component file: {$resolvedPath}");
        }

        $mtime = $stat['mtime'];
        $size = $stat['size'];

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

    /**
     * Retrieves the inline script content for a component IF the hash matches.
     *
     * @param string $name Component logical name.
     * @param string $hash 8-character sha1 prefix hash.
     * @return string|null JavaScript content or null if unknown, malformed, or hash mismatch.
     */
    public function scriptFor(string $name, string $hash): ?string
    {
        try {
            $component = $this->load($name);
        } catch (Exception) {
            return null;
        }

        $script = $component->view->getInlineScript();
        if ($script === null) {
            return null;
        }

        $computedHash = substr(sha1($script), 0, 8);
        if (!hash_equals($computedHash, $hash)) {
            return null;
        }

        return $script;
    }
}