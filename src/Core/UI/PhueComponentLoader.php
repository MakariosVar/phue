<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use Exception;
use LogicException;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\Exceptions\CircularComponentReferenceException;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Class PhueComponentLoader
 *
 * Loads, parses, and evaluates single-file Phue components (.phue).
 * Handles structural AST caching and dynamic runtime context evaluation.
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
     * Caches ONLY static raw structure per file path in-memory for the current request pass.
     *
     * @var array<string, array{mtime: int, size: int, contentHash: string, structure: array{template: string, script: ?string, phpCode: ?string, filePath?: string}}>
     */
    private array $cache = [];

    /**
     * L1 in-memory memoization array for compiled SSR modules (Per-process lifetime).
     * Mapped by component name -> array{key: string, code: string, hash: string}.
     *
     * @var array<string, array{key: string, code: string, hash: string}>
     */
    private array $ssrMemo = [];

    /**
     * L1 in-memory memoization array for compiled Client modules (Per-process lifetime).
     * Mapped by component name -> array{key: string, code: string, hash: string}.
     *
     * @var array<string, array{key: string, code: string, hash: string}>
     */
    private array $clientMemo = [];

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
     * Per-request snapshot component resolution index mapping logical component name -> relative path.
     *
     * @var array<string, string>|null
     */
    private ?array $componentIndex = null;

    /**
     * Process-lifetime memoization for parsed children component tags keyed by file path.
     * Content-hash invalidated so it survives process boundaries safely.
     *
     * @var array<string, array{hash: string, children: array<int, string>}>
     */
    private array $templateChildrenMemo = [];

    /**
     * Optional PSR-3 logger instance for diagnostic warnings.
     *
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * PhueComponentLoader constructor.
     *
     * @param string                  $componentsDir   Absolute or relative path to component files directory.
     * @param PhueTemplateParser|null $parser          Optional parser instance.
     * @param CacheInterface|null     $persistentCache Optional PSR-16 cache implementation.
     * @param string|null             $viewsCacheDir   Optional compiled PHP views cache directory for OPCache optimization.
     * @param TemplateCompilerBridge|null $compilerBridge Required template compiler bridge instance.
     * @param LoggerInterface|null    $logger          Optional PSR-3 logger instance.
     *
     * @throws RuntimeException If $componentsDir path is invalid or unreadable.
     * @throws LogicException   If $compilerBridge parameter is not provided.
     */
    public function __construct(
        string $componentsDir,
        ?PhueTemplateParser $parser = null,
        ?CacheInterface $persistentCache = null,
        ?string $viewsCacheDir = null,
        ?TemplateCompilerBridge $compilerBridge = null,
        ?LoggerInterface $logger = null
    ) {
        $realBase = realpath($componentsDir);
        if ($realBase === false || !is_dir($realBase)) {
            throw new RuntimeException("Invalid component directory path: {$componentsDir}");
        }

        if ($compilerBridge === null) {
            throw new LogicException("No TemplateCompilerBridge injected into PhueComponentLoader for dir '{$componentsDir}'. A shared compiler bridge is required.");
        }

        $this->componentsDir = $realBase;
        $this->parser = $parser ?? new PhueTemplateParser($viewsCacheDir);
        $this->persistentCache = $persistentCache;
        $this->compilerBridge = $compilerBridge;
        $this->logger = $logger;
    }

    /**
     * Per-request lifecycle boundary reset.
     * Clears per-request snapshot index and transient raw structure AST caches.
     *
     * @return void
     */
    public function beginRequest(): void
    {
        $this->componentIndex = null;
        $this->cache = [];
    }

    /**
     * Full process-level memoization flush.
     * Clears per-request state along with compiled client, SSR, and children AST memo caches.
     *
     * @return void
     */
    public function clearMemo(): void
    {
        $this->beginRequest();
        $this->ssrMemo = [];
        $this->clientMemo = [];
        $this->templateChildrenMemo = [];
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
     * Checks if a component with the given logical name exists and is accessible in $O(1)$ time.
     *
     * @param string $name Component logical name.
     *
     * @return bool True if component exists, false otherwise.
     */
    public function hasComponent(string $name): bool
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-\/]*$/', $name) || str_contains($name, '..')) {
            return false;
        }

        $index = $this->getComponentIndex();

        return isset($index[$name]);
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
        } catch (Throwable $e) {
            $this->logger?->warning("Failed to fetch component structure in getStructure()", [
                'name' => $name,
                'exception' => $e,
            ]);
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
        } catch (Throwable $e) {
            $this->logger?->warning("Failed to get content in contentFor()", [
                'name' => $name,
                'exception' => $e,
            ]);
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
            $module = $this->buildModuleInstance($structure['template'], $structure['script'] ?? null);

            return substr(sha1($module), 0, 8);
        } catch (Throwable $e) {
            $this->logger?->warning("Failed to compute raw module hash in rawModuleHash()", [
                'name' => $name,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Computes SHA-1 hash prefix for SSR compiled module without double compilation.
     *
     * @param string $name Component logical name.
     *
     * @return string|null 8-character sha1 hash prefix or null.
     */
    public function ssrModuleHash(string $name): ?string
    {
        $ssrSource = $this->buildForSsr($name);
        if ($ssrSource === null) {
            return null;
        }

        return substr(sha1($ssrSource), 0, 8);
    }

    /**
     * Computes SHA-1 hash prefix for Client compiled module in O(1) time if memoized.
     *
     * @param string $name Component logical name.
     *
     * @return string|null 8-character sha1 hash prefix or null.
     */
    public function clientModuleHash(string $name): ?string
    {
        return $this->moduleHash($name);
    }

    /**
     * Computes or resolves Client module hash.
     *
     * @param string $name Component logical name.
     *
     * @return string|null 8-character sha1 hash prefix or null.
     */
    public function moduleHash(string $name): ?string
    {
        $module = $this->buildFor($name);
        if ($module === null) {
            return null;
        }

        return $this->clientMemo[$name]['hash'] ?? substr(sha1($module), 0, 8);
    }

    /**
     * Builds the complete, dependency-aware JS SFC module for a given component name.
     * Fail-loud: If any child component fails to resolve or compile, returns null
     *
     * @param string $name Component logical name.
     *
     * @return string|null Compiled JavaScript module or null if component or dependencies cannot be loaded
     * @throws CircularComponentReferenceException If a circular dependency (cycle) is detected
     */
    public function buildFor(string $name): ?string
    {
        if (isset($this->buildingStack[$name])) {
            $chain = implode(' -> ', array_keys($this->buildingStack)) . ' -> ' . $name;
            throw new CircularComponentReferenceException("Circular component dependency detected in build path: {$chain}");
        }

        try {
            $structure = $this->getOrFetchStructure($name);
        } catch (Throwable $e) {
            $this->logger?->warning("Failed to fetch structure in buildFor()", [
                'name' => $name,
                'exception' => $e,
            ]);
            return null;
        }

        $filePath = $structure['filePath'] ?? '';
        $mtime = 0;
        $size = 0;
        $contentHash = '';

        if ($filePath !== '' && isset($this->cache[$filePath])) {
            $mtime = $this->cache[$filePath]['mtime'];
            $size = $this->cache[$filePath]['size'];
            $contentHash = $this->cache[$filePath]['contentHash'] ?? '';
        }

        $children = [];
        $resolvedChildHashes = [];

        if ($this->registry !== null) {
            if ($contentHash !== '' && isset($this->templateChildrenMemo[$filePath]) && $this->templateChildrenMemo[$filePath]['hash'] === $contentHash) {
                $children = $this->templateChildrenMemo[$filePath]['children'];
            } else {
                $discoverer = new ComponentDiscoverer($this->registry, $this->logger);
                $children = $discoverer->scanTemplate($structure['template'], $name);
                if ($contentHash !== '') {
                    $this->templateChildrenMemo[$filePath] = [
                        'hash' => $contentHash,
                        'children' => $children,
                    ];
                }
            }

            $this->buildingStack[$name] = true;
            try {
                foreach ($children as $child) {
                    $hash = $this->registry->moduleHash($child);
                    // Fail-loud: if child resolution fails, fail the whole parent build immediately
                    if ($hash === null) {
                        $this->logger?->warning("Could not resolve module hash for child component '{$child}', aborting parent build '{$name}'", [
                            'parent' => $name,
                            'child' => $child,
                        ]);
                        return null; // Fail-loud contract
                    }
                    $resolvedChildHashes[$child] = $hash;
                }
            } finally {
                unset($this->buildingStack[$name]);
            }
        }

        $fingerprint = "{$mtime}_{$size}_{$contentHash}_" . implode('_', $resolvedChildHashes);

        if (isset($this->clientMemo[$name]) && $this->clientMemo[$name]['key'] === $fingerprint) {
            return $this->clientMemo[$name]['code'];
        }

        $imports = [];
        $childNames = [];

        foreach ($children as $child) {
            $servableHash = $resolvedChildHashes[$child] ?? null;
            if ($servableHash === null) {
                return null; // Fail-loud safety check
            }

            $rawImportName = "__phueRaw_{$child}";
            $renderImportName = "__phueRender_{$child}";

            $importPath = "/assets/phue/{$child}.{$servableHash}.phue.js";

            $imports[] = "import {$rawImportName}, { __phueRender as {$renderImportName} } from '{$importPath}';";
            $imports[] = "const {$child} = { ...({$rawImportName} || {}), render: {$renderImportName} };";

            $childNames[] = $child;
        }

        $importStatements = !empty($imports) ? implode("\n", $imports) . "\n" : '';
        $componentsObj = !empty($childNames) ? implode(', ', $childNames) : '';

        try {
            $renderFnCode = $this->compilerBridge->compile($structure['template'], ssr: false);
        } catch (Throwable $e) {
            // Log warning before returning null
            $this->logger?->warning("Failed to compile template in buildFor()", [
                'name' => $name,
                'exception' => $e,
            ]);
            return null;
        }

        $scriptContent = trim($structure['script'] ?? '');

        if ($scriptContent === '') {
            $componentsProp = !empty($componentsObj) ? ", components: { {$componentsObj} }" : '';
            $code = "{$importStatements}{$renderFnCode}\n"
                 . "export const __phueRender = render;\n"
                 . "export default { render: __phueRender{$componentsProp} };\n";

            $hash = substr(sha1($code), 0, 8);
            $this->clientMemo[$name] = ['key' => $fingerprint, 'code' => $code, 'hash' => $hash];
            return $code;
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

        $code = "{$importStatements}{$renderFnCode}\n"
             . "export const __phueRender = render;\n"
             . "{$proxyBlock}{$scriptContent}";

        $hash = substr(sha1($code), 0, 8);
        $this->clientMemo[$name] = ['key' => $fingerprint, 'code' => $code, 'hash' => $hash];
        return $code;
    }

    /**
     * Builds SSR-only JS module for Sidecar V8 execution.
     *
     * @param string $name
     * @return string|null
     * @throws CircularComponentReferenceException If a circular dependency (cycle) is detected
     */
    public function buildForSsr(string $name): ?string
    {
        if (isset($this->buildingStack[$name])) {
            $chain = implode(' -> ', array_keys($this->buildingStack)) . ' -> ' . $name;
            throw new CircularComponentReferenceException("Circular component dependency detected in SSR build path: {$chain}");
        }

        $this->buildingStack[$name] = true;

        try {
            try {
                $structure = $this->getOrFetchStructure($name);
            } catch (Throwable $e) {
                $this->logger?->warning("Failed to fetch structure in buildForSsr()", [
                    'name' => $name,
                    'exception' => $e,
                ]);
                return null;
            }

            $filePath = $structure['filePath'] ?? '';
            $mtime = 0;
            $size = 0;
            $contentHash = '';

            if ($filePath !== '' && isset($this->cache[$filePath])) {
                $mtime = $this->cache[$filePath]['mtime'];
                $size = $this->cache[$filePath]['size'];
                $contentHash = $this->cache[$filePath]['contentHash'] ?? '';
            }

            $fingerprint = "{$mtime}_{$size}_{$contentHash}";

            if (isset($this->ssrMemo[$name]) && $this->ssrMemo[$name]['key'] === $fingerprint) {
                return $this->ssrMemo[$name]['code'];
            }

            try {
                $ssrRenderFnCode = $this->compilerBridge->compile($structure['template'], ssr: true);
            } catch (Throwable $e) {
                $this->logger?->warning("Failed to compile SSR template in buildForSsr()", [
                    'name' => $name,
                    'exception' => $e,
                ]);
                return null;
            }

            $scriptContent = trim($structure['script'] ?? '');

            if ($scriptContent === '') {
                $code = "{$ssrRenderFnCode}\n"
                      . "export const __phueSsrRender = ssrRender;\n"
                      . "export default { ssrRender: __phueSsrRender };\n";

                $hash = substr(sha1($code), 0, 8);
                $this->ssrMemo[$name] = ['key' => $fingerprint, 'code' => $code, 'hash' => $hash];
                return $code;
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

            $code = "{$ssrRenderFnCode}\n"
                  . "export const __phueSsrRender = ssrRender;\n"
                  . "{$proxyBlock}{$scriptContent}";

            $hash = substr(sha1($code), 0, 8);
            $this->ssrMemo[$name] = ['key' => $fingerprint, 'code' => $code, 'hash' => $hash];
            return $code;
        } finally {
            unset($this->buildingStack[$name]);
        }
    }

    /**
     * Internal helper to build a module string given a template and script using the injected compiler bridge.
     *
     * @param string      $template
     * @param string|null $script
     * @return string
     */
    public function buildModuleInstance(string $template, ?string $script): string
    {
        try {
            $renderFnCode = $this->compilerBridge->compile($template, ssr: false);
        } catch (Throwable $e) {
            $this->logger?->warning("Failed to compile template in buildModuleInstance()", [
                'exception' => $e,
            ]);
            return '';
        }

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
     * Builds and returns the per-request snapshot index mapping logical names -> relative file paths.
     *
     * @return array<string, string>
     */
    private function getComponentIndex(): array
    {
        if ($this->componentIndex !== null) {
            return $this->componentIndex;
        }

        $index = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->componentsDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

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

                $exactName = substr($relativePath, 0, -5);

                $candidates[] = [
                    'exactName' => $exactName,
                    'basename' => $basename,
                    'relativePath' => $relativePath,
                    'depth' => $depth,
                ];
            }
        }

        usort($candidates, fn($a, $b) => $a['depth'] <=> $b['depth']);

        foreach ($candidates as $candidate) {
            $relativePath = $candidate['relativePath'];
            
            $index[$candidate['exactName']] = $relativePath;

            if (!isset($index[$candidate['basename']])) {
                $index[$candidate['basename']] = $relativePath;
            }
        }

        return $this->componentIndex = $index;
    }

    /**
     * Fetches component structure from disk or cache using O(1) index lookup.
     *
     * @param string $name
     * @param bool $isRetry
     * @return array
     * @throws RuntimeException If component name format is invalid, file is missing, or path containment fails.
     */
    private function getOrFetchStructure(string $name, bool $isRetry = false): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-\/]*$/', $name) || str_contains($name, '..')) {
            throw new RuntimeException("Invalid component name format: '{$name}'");
        }

        $index = $this->getComponentIndex();

        if (!isset($index[$name])) {
            throw new RuntimeException("Phue component file not found: '{$name}'");
        }

        $targetPath = $this->componentsDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $index[$name]);
        $resolvedPath = realpath($targetPath);

        if ($resolvedPath === false) {
            if (!$isRetry) {
                $this->componentIndex = null;
                return $this->getOrFetchStructure($name, isRetry: true);
            }
            throw new RuntimeException("Phue component file inaccessible: '{$name}'");
        }

        if (!str_starts_with($resolvedPath, $this->componentsDir . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Phue component file outside components directory: '{$name}'");
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

        $contentHash = sha1_file($resolvedPath) ?: '';

        $cacheKey = 'phue_structure_' . md5($resolvedPath);
        if ($this->persistentCache !== null) {
            $persistentData = $this->persistentCache->get($cacheKey);
            if (
                is_array($persistentData) &&
                isset($persistentData['mtime'], $persistentData['size'], $persistentData['contentHash'], $persistentData['structure']) &&
                $persistentData['mtime'] === $mtime &&
                $persistentData['size'] === $size &&
                hash_equals($persistentData['contentHash'], $contentHash)
            ) {
                $this->cache[$resolvedPath] = $persistentData;
                $persistentData['structure']['filePath'] = $resolvedPath;
                return $persistentData['structure'];
            }
        }

        $structure = $this->parser->parseStructure($resolvedPath);
        $structure['filePath'] = $resolvedPath;

        $payload = [
            'mtime'       => $mtime,
            'size'        => $size,
            'contentHash' => $contentHash,
            'structure'   => $structure,
        ];

        $this->cache[$resolvedPath] = $payload;

        if ($this->persistentCache !== null) {
            $this->persistentCache->set($cacheKey, $payload);
        }

        return $structure;
    }
}