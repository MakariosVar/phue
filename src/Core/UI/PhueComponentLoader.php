<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Exception;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Psr\SimpleCache\CacheInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
     * @var array<string, array{mtime: int, size: int, structure: array{template: string, script: ?string, phpCode: ?string, filePath?: string}}>
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
     * Template to JS render function compiler bridge instance.
     *
     * @var TemplateCompilerBridge
     */
    private TemplateCompilerBridge $compilerBridge;

    /**
     * Lazy recursive child directory index mapping basename -> relative path.
     *
     * @var array<string, string>|null
     */
    private ?array $childIndex = null;

    /**
     * PhueComponentLoader constructor.
     *
     * @param string                  $componentsDir   Absolute or relative path to component files directory.
     * @param PhueTemplateParser|null $parser          Optional parser instance.
     * @param CacheInterface|null     $persistentCache Optional PSR-16 cache implementation.
     * @param string|null             $viewsCacheDir   Optional compiled PHP views cache directory for OPCache optimization.
     * @param TemplateCompilerBridge|null $compilerBridge Optional template compiler bridge instance.
     *
     * @throws RuntimeException If $componentsDir path is invalid or unreadable.
     */
    public function __construct(
        string $componentsDir,
        ?PhueTemplateParser $parser = null,
        ?CacheInterface $persistentCache = null,
        ?string $viewsCacheDir = null,
        ?TemplateCompilerBridge $compilerBridge = null
    ) {
        $realBase = realpath($componentsDir);
        if ($realBase === false || !is_dir($realBase)) {
            throw new RuntimeException("Invalid component directory path: {$componentsDir}");
        }

        $this->componentsDir = $realBase;
        $this->parser = $parser ?? new PhueTemplateParser($viewsCacheDir);
        $this->persistentCache = $persistentCache;
        $this->compilerBridge = $compilerBridge ?? new TemplateCompilerBridge();
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
     * @return array{template: string, script: ?string, phpCode: ?string, filePath?: string}|null AST structure or null.
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
                $servableHash = $this->registry !== null
                    ? $this->registry->moduleHash($child)
                    : $this->moduleHash($child);

                if ($servableHash !== null) {
                    $rawImportName = "__phueRaw_{$child}";
                    $renderImportName = "__phueRender_{$child}";

                    $imports[] = "import {$rawImportName}, { __phueRender as {$renderImportName} } from '/assets/phue/{$child}.{$servableHash}.phue.js';";
                    $imports[] = "const {$child} = { ...({$rawImportName} || {}), render: {$renderImportName} };";

                    $childNames[] = $child;
                }
            }
        } finally {
            unset($this->buildingStack[$name]);
        }

        $importStatements = !empty($imports) ? implode("\n", $imports) . "\n" : '';
        $componentsObj = !empty($childNames) ? implode(', ', $childNames) : '';

        $renderFnCode = $this->compilerBridge->compile($structure['template'], ssr: false);
        $scriptContent = trim($structure['script'] ?? '');

        if ($scriptContent === '') {
            $componentsProp = !empty($componentsObj) ? ", components: { {$componentsObj} }" : '';
            return "{$importStatements}{$renderFnCode}\n"
                 . "export const __phueRender = render;\n"
                 . "export default { render: __phueRender{$componentsProp} };\n";
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
                $proxies[] = "const createSSRApp = (opts, rootProps) => __phueOrigCreateSSRApp({ render: __phueRender, ...opts{$componentsProp} }, rootProps);";
            }
            if (str_contains($scriptContent, '__phueOrigCreateApp')) {
                $proxies[] = "const createApp = (opts, rootProps) => __phueOrigCreateApp({ render: __phueRender, ...opts{$componentsProp} }, rootProps);";
            }

            $proxyBlock = implode("\n", $proxies) . "\n";
        } elseif (!empty($componentsObj)) {
            $scriptContent = preg_replace_callback(
                '/export\s+default\s*\{/s',
                fn($matches) => "export default { components: { {$componentsObj} }, ",
                $scriptContent,
                1
            );
        }

        return "{$importStatements}{$renderFnCode}\n"
             . "export const __phueRender = render;\n"
             . "{$proxyBlock}{$scriptContent}";
    }

    /**
     * Builds SSR-only JS module for Sidecar V8 execution.
     *
     * @param string $name
     * @return string|null
     */
    public function buildForSsr(string $name): ?string
    {
        try {
            $structure = $this->getOrFetchStructure($name);
        } catch (Exception) {
            return null;
        }

        $ssrRenderFnCode = $this->compilerBridge->compile($structure['template'], ssr: true);
        $scriptContent = trim($structure['script'] ?? '');

        if ($scriptContent === '') {
            return "{$ssrRenderFnCode}\n"
                    . "export const __phueSsrRender = ssrRender;\n"
                    . "export default { ssrRender: __phueSsrRender };\n";
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

            $proxies = [];
            if (str_contains($scriptContent, '__phueOrigCreateSSRApp')) {
                $proxies[] = "const createSSRApp = (opts, rootProps) => __phueOrigCreateSSRApp({ ssrRender: __phueSsrRender, ...opts }, rootProps);";
            }
            if (str_contains($scriptContent, '__phueOrigCreateApp')) {
                $proxies[] = "const createApp = (opts, rootProps) => __phueOrigCreateApp({ ssrRender: __phueSsrRender, ...opts }, rootProps);";
            }

            $proxyBlock = implode("\n", $proxies) . "\n";
        }

        return "{$ssrRenderFnCode}\n"
                . "export const __phueSsrRender = ssrRender;\n"
                . "{$proxyBlock}{$scriptContent}";
    }

    /**
     * Builds a module string given a template and script.
     *
     * @param string      $template
     * @param string|null $script
     * @return string
     */
    public static function buildModule(string $template, ?string $script): string
    {
        $compiler = new TemplateCompilerBridge();
        $renderFnCode = $compiler->compile($template, ssr: false);

        $scriptContent = trim($script ?? '');

        if ($scriptContent === '') {
            return "{$renderFnCode}\n"
                 . "export const __phueRender = render;\n"
                 . "export default { render: __phueRender };\n";
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

            $proxies = [];
            if (str_contains($scriptContent, '__phueOrigCreateSSRApp')) {
                $proxies[] = 'const createSSRApp = (opts, rootProps) => __phueOrigCreateSSRApp({ render: __phueRender, ...opts }, rootProps);';
            }
            if (str_contains($scriptContent, '__phueOrigCreateApp')) {
                $proxies[] = 'const createApp = (opts, rootProps) => __phueOrigCreateApp({ render: __phueRender, ...opts }, rootProps);';
            }

            $proxyBlock = implode("\n", $proxies) . "\n";
        }

        return "{$renderFnCode}\n"
             . "export const __phueRender = render;\n"
             . "{$proxyBlock}{$scriptContent}";
    }

    /**
     * Returns the built module if the hash matches.
     *
     * @param string $name
     * @param string $hash
     * @return string|null
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
     * Returns the module hash for a component.
     *
     * @param string $name
     * @return string|null
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
     * Evaluates a component structure with parameters to return a PhueComponent.
     *
     * @param array{template: string, script: ?string, style?: ?string, phpCode: ?string, phpStartLine?: int, filePath?: string} $structure
     * @param array<string, mixed> $params
     * @return PhueComponent
     */
    private function evaluateStructure(array $structure, array $params): PhueComponent
    {
        $meta = $this->parser->evaluatePhpBlock(
            $structure['phpCode'] ?? '', 
            $params, 
            $structure['filePath'] ?? null, 
            $structure['phpStartLine'] ?? 1
        );

        $title = (string) ($meta['title'] ?? 'Phue App');
        $state = is_array($meta['state'] ?? null) ? $meta['state'] : [];
        $scripts = is_array($meta['scripts'] ?? null) ? $meta['scripts'] : [];
        $styles = is_array($meta['styles'] ?? null) ? $meta['styles'] : [];
        $html = is_string($meta['html'] ?? null) ? $meta['html'] : null;

        $view = new View(
            template: $structure['template'],
            state: $state,
            scripts: $scripts,
            styles: $styles,
            inlineScript: $structure['script'],
            html: $html,
            style: $structure['style'] ?? null
        );

        return new PhueComponent($title, $view);
    }

    /**
     * Builds the lazy recursive child index mapping basenames to relative paths.
     * Uses shallowest path first for deterministic collision policy.
     *
     * @return array<string, string>
     */
    private function getChildIndex(): array
    {
        if ($this->childIndex !== null) {
            return $this->childIndex;
        }

        $index = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->componentsDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        // Collect all candidate files with their directory depths to favor shallowest paths on collision
        $candidates = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'phue') {
                $filePath = $file->getRealPath();
                if ($filePath === false) {
                    continue;
                }

                $relativePath = substr($filePath, strlen($this->componentsDir) + 1);
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
                
                $basename = pathinfo($relativePath, PATHINFO_FILENAME);
                $depth = substr_count($relativePath, '/');

                $candidates[] = [
                    'basename' => $basename,
                    'relativePath' => $relativePath,
                    'depth' => $depth,
                ];
            }
        }

        // Sort by depth ascending so shallower paths take precedence
        usort($candidates, fn($a, $b) => $a['depth'] <=> $b['depth']);

        foreach ($candidates as $candidate) {
            $basename = $candidate['basename'];
            // First one wins (shallowest)
            if (!isset($index[$basename])) {
                $index[$basename] = $candidate['relativePath'];
            }
        }

        return $this->childIndex = $index;
    }

    /**
     * Fetches component structure from disk or cache.
     *
     * @param string $name
     * @return array
     */
    private function getOrFetchStructure(string $name): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-\/]*$/', $name) || str_contains($name, '..')) {
            throw new RuntimeException("Invalid component name format: '{$name}'");
        }

        $targetPath = $this->componentsDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name) . '.phue';

        // Flat path check first (fast path)
        if (!file_exists($targetPath)) {
            // Fallback to lazy recursive child index lookup by basename
            $childIndex = $this->getChildIndex();
            
            if (isset($childIndex[$name])) {
                $targetPath = $this->componentsDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $childIndex[$name]);
            } else {
                // If index lookup misses, invalidate cache/index once for Swoole freshness staleness check and retry
                $this->childIndex = null;
                $childIndex = $this->getChildIndex();

                if (isset($childIndex[$name])) {
                    $targetPath = $this->componentsDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $childIndex[$name]);
                } else {
                    throw new RuntimeException("Phue component file not found: '{$name}'");
                }
            }
        }

        $resolvedPath = realpath($targetPath);
        if ($resolvedPath === false || !str_starts_with($resolvedPath, $this->componentsDir . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Phue component file inaccessible: '{$name}'");
        }

        clearstatcache(true, $resolvedPath);
        $stat = stat($resolvedPath);
        if ($stat === false) {
            throw new RuntimeException("Failed to stat component file: {$resolvedPath}");
        }

        $mtime = $stat['mtime'];
        $size = $stat['size'];

        if (isset($this->cache[$resolvedPath])) {
            $cached = $this->cache[$resolvedPath];
            if ($cached['mtime'] === $mtime && $cached['size'] === $size) {
                $cached['structure']['filePath'] = $resolvedPath;
                return $cached['structure'];
            }
        }

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
                $persistentData['structure']['filePath'] = $resolvedPath;
                return $persistentData['structure'];
            }
        }

        $structure = $this->parser->parseStructure($resolvedPath);
        $structure['filePath'] = $resolvedPath;

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