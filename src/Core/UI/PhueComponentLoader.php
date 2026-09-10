<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Exception;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * Class PhueComponentLoader
 *
 * Loads, parses, and evaluates single-file Phue components (.phue).
 * Handles structural AST caching (in-memory & PSR-16 persistent) and dynamic runtime context evaluation.
 *
 * @package Phue\Core\UI
 */
class PhueComponentLoader
{
    /**
     * Base directory containing .phue component files.
     *
     * @var string
     */
    private string $componentsDir;

    /**
     * Caches ONLY static raw structure per file path in-memory for the current request.
     *
     * @var array<string, array{mtime: int, size: int, structure: array{template: string, script: ?string, phpCode: ?string}}>
     */
    private array $cache = [];

    /**
     * Structural template parser instance.
     *
     * @var PhueTemplateParser
     */
    private PhueTemplateParser $parser;

    /**
     * Optional PSR-16 persistent cache interface for cross-request structure caching.
     *
     * @var CacheInterface|null
     */
    private ?CacheInterface $persistentCache;

    /**
     * PhueComponentLoader constructor.
     *
     * @param string                  $componentsDir   Absolute or relative path to component files directory.
     * @param PhueTemplateParser|null $parser          Optional parser instance.
     * @param CacheInterface|null     $persistentCache Optional PSR-16 cache implementation.
     * @param string|null             $viewsCacheDir   Optional compiled PHP views cache directory for OPCache optimization.
     *
     * @throws RuntimeException If $componentsDir path is invalid or unreadable.
     */
    public function __construct(
        string $componentsDir,
        ?PhueTemplateParser $parser = null,
        ?CacheInterface $persistentCache = null,
        ?string $viewsCacheDir = null
    ) {
        $realBase = realpath($componentsDir);
        if ($realBase === false || !is_dir($realBase)) {
            throw new RuntimeException("Invalid component directory path: {$componentsDir}");
        }

        $this->componentsDir = $realBase;
        $this->parser = $parser ?? new PhueTemplateParser($viewsCacheDir);
        $this->persistentCache = $persistentCache;
    }

    /**
     * Loads, parses (or fetches cached structure), and dynamically evaluates PHP block with $params.
     *
     * @param string               $name   Component logical name.
     * @param array<string, mixed> $params Parameters passed into the <php> evaluation context.
     *
     * @return PhueComponent Evaluated component entity containing title and View instance.
     *
     * @throws RuntimeException If component name is invalid or file is missing/unreadable.
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
     * @return string|null Inline JavaScript content or null if not found or has no script block.
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
     * Retrieves the inline script content for a component IF the sha1 prefix hash matches.
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
     * Evaluates parsed structure dynamically per-render call and extracts meta declarations.
     *
     * @param array{template: string, script: ?string, phpCode: ?string} $structure Structural AST array.
     * @param array<string, mixed>                                       $params    Evaluation parameters.
     *
     * @return PhueComponent Constructed component entity.
     */
    private function evaluateStructure(array $structure, array $params): PhueComponent
    {
        $meta = $this->parser->evaluatePhpBlock($structure['phpCode'] ?? '', $params);

        $title = (string) ($meta['title'] ?? 'Phue App');
        $state = is_array($meta['state'] ?? null) ? $meta['state'] : [];
        $scripts = is_array($meta['scripts'] ?? null) ? $meta['scripts'] : [];
        $html = is_string($meta['html'] ?? null) ? $meta['html'] : null;

        $view = new View(
            template: $structure['template'],
            state: $state,
            scripts: $scripts,
            inlineScript: $structure['script'],
            html: $html
        );

        return new PhueComponent($title, $view);
    }

    /**
     * Resolves file path and returns/caches ONLY the static AST structure based on mtime and size.
     *
     * @param string $name Component logical name.
     *
     * @return array{template: string, script: ?string, phpCode: ?string} Structural AST payload.
     *
     * @throws RuntimeException If component name format is invalid or file path escapes sandbox.
     */
    private function getOrFetchStructure(string $name): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-\/]*$/', $name) || str_contains($name, '..')) {
            throw new RuntimeException("Invalid component name format: '{$name}'");
        }

        $targetPath = $this->componentsDir . DIRECTORY_SEPARATOR . $name . '.phue';

        // Fast preliminary check to avoid unneeded realpath() & stat() calls
        if (!file_exists($targetPath)) {
            throw new RuntimeException("Phue component file not found: '{$name}'");
        }

        $resolvedPath = realpath($targetPath);
        if ($resolvedPath === false || !str_starts_with($resolvedPath, $this->componentsDir . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Phue component file inaccessible: '{$name}'");
        }

        $stat = stat($resolvedPath);
        if ($stat === false) {
            throw new RuntimeException("Failed to stat component file: {$resolvedPath}");
        }

        $mtime = $stat['mtime'];
        $size = $stat['size'];

        // 1. In-Memory Request Cache Hit
        if (isset($this->cache[$resolvedPath])) {
            $cached = $this->cache[$resolvedPath];
            if ($cached['mtime'] === $mtime && $cached['size'] === $size) {
                return $cached['structure'];
            }
        }

        // 2. PSR-16 Persistent Cache Hit
        $cacheKey = 'phue_structure_' . md5($resolvedPath);
        if ($this->persistentCache !== null) {
            $persistentData = $this->persistentCache->get($cacheKey);
            if (
                is_array($persistentData) &&
                isset($persistentData['mtime'], $persistentData['size'], $persistentData['structure']) &&
                $persistentData['mtime'] === $mtime &&
                $persistentData['size'] === $size
            ) {
                $this->cache[$resolvedPath] = $persistentData;
                return $persistentData['structure'];
            }
        }

        // 3. Cache Miss — Parse file from disk
        $structure = $this->parser->parseStructure($resolvedPath);

        $payload = [
            'mtime'     => $mtime,
            'size'      => $size,
            'structure' => $structure,
        ];

        $this->cache[$resolvedPath] = $payload;

        if ($this->persistentCache !== null) {
            $this->persistentCache->set($cacheKey, $payload);
        }

        return $structure;
    }

    /**
     * Builds the unified JavaScript SFC module containing the stringified template export and inline script.
     *
     * @param string      $template HTML/Vue template string.
     * @param string|null $script   Inline JavaScript component options payload.
     *
     * @return string Valid JavaScript module content.
     */
    public static function buildModule(string $template, ?string $script): string
    {
        $jsonTemplate = json_encode(
            $template,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_THROW_ON_ERROR
        );

        $scriptContent = trim($script ?? '');

        return "export const __phueTemplate = {$jsonTemplate};\n" . $scriptContent;
    }

    /**
     * Serves the compiled JS SFC module for a component IF the 8-character sha1 prefix hash matches.
     * Performs zero PHP evaluation.
     *
     * @param string $name Component logical name.
     * @param string $hash 8-character sha1 prefix hash.
     *
     * @return string|null JavaScript SFC module content or null if unknown or hash mismatch.
     */
    public function moduleFor(string $name, string $hash): ?string
    {
        try {
            $structure = $this->getOrFetchStructure($name);
        } catch (Exception) {
            return null;
        }

        $module = self::buildModule($structure['template'], $structure['script'] ?? null);
        $computedHash = substr(sha1($module), 0, 8);

        if (!hash_equals($computedHash, $hash)) {
            return null;
        }

        return $module;
    }

    /**
     * Computes the 8-character SHA-1 hash prefix of the compiled JS SFC module.
     *
     * @param string $name Component logical name.
     *
     * @return string|null 8-character hash prefix, or null if component cannot be loaded/parsed.
     */
    public function moduleHash(string $name): ?string
    {
        try {
            $structure = $this->getOrFetchStructure($name);
            $module = self::buildModule($structure['template'], $structure['script'] ?? null);

            return substr(sha1($module), 0, 8);
        } catch (Exception) {
            return null;
        }
    }
}