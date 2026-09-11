<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Exception;
use Phue\Core\Frontend\ComponentLoaderRegistry;
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
     * Optional registry instance for dependency discovery and resolution.
     *
     * @var ComponentLoaderRegistry|null
     */
    private ?ComponentLoaderRegistry $registry = null;

    /**
     * Stack tracking currently building components to detect circular dependencies in DAG.
     *
     * @var array<string, bool>
     */
    private array $buildingStack = [];

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
     * Sets the loader registry instance for resolving child component dependencies.
     *
     * @param ComponentLoaderRegistry $registry
     *
     * @return void
     */
    public function setRegistry(ComponentLoaderRegistry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * Checks if a component with the given logical name exists and is accessible.
     *
     * @param string $name Component logical name.
     *
     * @return bool True if component exists, false otherwise.
     */
    public function hasComponent(string $name): bool
    {
        try {
            $this->getOrFetchStructure($name);
            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Retrieves the structural AST of a component without throwing exceptions.
     *
     * @param string $name Component logical name.
     *
     * @return array{template: string, script: ?string, phpCode: ?string}|null AST structure or null.
     */
    public function getStructure(string $name): ?array
    {
        try {
            return $this->getOrFetchStructure($name);
        } catch (Exception) {
            return null;
        }
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
     * Computes raw module SHA-1 hash (without dependency resolution or registry scanning).
     *
     * @param string $name Component logical name.
     *
     * @return string|null 8-character sha1 hash prefix or null.
     */
    public function rawModuleHash(string $name): ?string
    {
        try {
            $structure = $this->getOrFetchStructure($name);
            $module = self::buildModule($structure['template'], $structure['script'] ?? null);

            return substr(sha1($module), 0, 8);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Builds the complete, dependency-aware JS SFC module for a given component name.
     *
     * @param string $name Component logical name.
     *
     * @return string|null Compiled JavaScript module or null if component cannot be loaded.
     * @throws RuntimeException If a circular dependency (cycle) is detected.
     */
    public function buildFor(string $name): ?string
    {
        if (isset($this->buildingStack[$name])) {
            $chain = implode(' -> ', array_keys($this->buildingStack)) . ' -> ' . $name;
            throw new RuntimeException("Circular component dependency detected in build path: {$chain}");
        }

        $this->buildingStack[$name] = true;

        try {
            $structure = $this->getOrFetchStructure($name);
        } catch (Exception) {
            unset($this->buildingStack[$name]);
            return null;
        }

        $children = [];
        if ($this->registry !== null) {
            $discoverer = new ComponentDiscoverer($this->registry);
            $children = $discoverer->scanTemplate($structure['template']);
        }

        $imports = [];
        $childNames = [];

        try {
            foreach ($children as $child) {
                // Use full servable module hash via moduleHash instead of rawModuleHash
                $servableHash = $this->registry !== null
                    ? $this->registry->moduleHash($child)
                    : $this->moduleHash($child);

                if ($servableHash !== null) {
                    $rawImportName = "__phueRaw_{$child}";
                    $templateImportName = "__phueTpl_{$child}";

                    $imports[] = "import {$rawImportName}, { __phueTemplate as {$templateImportName} } from '/assets/phue/{$child}.{$servableHash}.phue.js';";
                    $imports[] = "const {$child} = { ...({$rawImportName} || {}), template: {$templateImportName} };";

                    $childNames[] = $child;
                }
            }
        } finally {
            unset($this->buildingStack[$name]);
        }

        $importStatements = !empty($imports) ? implode("\n", $imports) . "\n" : '';
        $componentsObj = !empty($childNames) ? implode(', ', $childNames) : '';

        $jsonTemplate = json_encode(
            $structure['template'],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_THROW_ON_ERROR
        );

        $scriptContent = trim($structure['script'] ?? '');

        if ($scriptContent === '') {
            $componentsProp = !empty($componentsObj) ? ", components: { {$componentsObj} }" : '';
            return "{$importStatements}export const __phueTemplate = {$jsonTemplate};\nexport default { template: __phueTemplate{$componentsProp} };\n";
        }

        $hasSsrApp = preg_match('/\bcreateSSRApp\b/', $scriptContent) === 1;
        $hasApp    = preg_match('/\bcreateApp\b/', $scriptContent) === 1;

        $proxyBlock = '';

        if ($hasSsrApp || $hasApp) {
            $scriptContent = preg_replace_callback(
                '/import\s+\{([^}]+)\}\s+from\s+([\'"].*?[\'"])/s',
                function ($matches) {
                    $imports = $matches[1];
                    $source  = $matches[2];

                    $imports = preg_replace('/\bcreateSSRApp\b/', 'createSSRApp as __phueOrigCreateSSRApp', $imports);
                    $imports = preg_replace('/\bcreateApp\b/', 'createApp as __phueOrigCreateApp', $imports);

                    return "import {{$imports}} from {$source};";
                },
                $scriptContent,
                1
            );

            $componentsProp = !empty($componentsObj) 
                ? ", components: { ...(opts.components || {}), {$componentsObj} }" 
                : '';

            $proxies = [];
            if (str_contains($scriptContent, '__phueOrigCreateSSRApp')) {
                $proxies[] = "const createSSRApp = (opts, rootProps) => __phueOrigCreateSSRApp({ template: __phueTemplate, ...opts{$componentsProp} }, rootProps);";
            }
            if (str_contains($scriptContent, '__phueOrigCreateApp')) {
                $proxies[] = "const createApp = (opts, rootProps) => __phueOrigCreateApp({ template: __phueTemplate, ...opts{$componentsProp} }, rootProps);";
            }

            $proxyBlock = implode("\n", $proxies) . "\n";
        } elseif (!empty($componentsObj)) {
            // Non-root script components (e.g. Card): inject child components into default export
            $scriptContent = preg_replace_callback(
                '/export\s+default\s*\{/s',
                fn($matches) => "export default { components: { {$componentsObj} }, ",
                $scriptContent,
                1
            );
        }

        return "{$importStatements}export const __phueTemplate = {$jsonTemplate};\n{$proxyBlock}{$scriptContent}";
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

        if ($scriptContent === '') {
            return "export const __phueTemplate = {$jsonTemplate};\nexport default { template: __phueTemplate };\n";
        }

        // Intercept createSSRApp or createApp imports from vue
        $hasSsrApp = preg_match('/\bcreateSSRApp\b/', $scriptContent) === 1;
        $hasApp    = preg_match('/\bcreateApp\b/', $scriptContent) === 1;

        $proxyBlock = '';

        if ($hasSsrApp || $hasApp) {
            // Alias imports in statement: createSSRApp -> __phueOrigCreateSSRApp
            $scriptContent = preg_replace_callback(
                '/import\s+\{([^}]+)\}\s+from\s+([\'"].*?[\'"])/s',
                function ($matches) {
                    $imports = $matches[1];
                    $source  = $matches[2];

                    $imports = preg_replace('/\bcreateSSRApp\b/', 'createSSRApp as __phueOrigCreateSSRApp', $imports);
                    $imports = preg_replace('/\bcreateApp\b/', 'createApp as __phueOrigCreateApp', $imports);

                    return "import {{$imports}} from {$source};";
                },
                $scriptContent,
                1
            );

            // Inject the proxy functions below imports
            $proxies = [];
            if (str_contains($scriptContent, '__phueOrigCreateSSRApp')) {
                $proxies[] = 'const createSSRApp = (opts, rootProps) => __phueOrigCreateSSRApp({ template: __phueTemplate, ...opts }, rootProps);';
            }
            if (str_contains($scriptContent, '__phueOrigCreateApp')) {
                $proxies[] = 'const createApp = (opts, rootProps) => __phueOrigCreateApp({ template: __phueTemplate, ...opts }, rootProps);';
            }

            $proxyBlock = implode("\n", $proxies) . "\n";
        }

        return "export const __phueTemplate = {$jsonTemplate};\n{$proxyBlock}{$scriptContent}";
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
        $module = $this->buildFor($name);
        if ($module === null) {
            return null;
        }

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
        $module = $this->buildFor($name);
        if ($module === null) {
            return null;
        }

        return substr(sha1($module), 0, 8);
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
}