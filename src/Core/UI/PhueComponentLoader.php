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
     * Optional directory containing shared UI toolkit JS modules (e.g. position.js, overlay.js, store.js).
     *
     * @var string|null
     */
    private ?string $jsDir = null;

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
     * Memo of resolved child module hashes, keyed by component name.
     *
     * Each entry records the identity of the child AND of every node in its subtree, so a
     * later visit can revalidate the whole branch with stats alone instead of rebuilding
     * it. Identity is the same (mtime, size, contentHash) triple the client memo's
     * fingerprint is built from, which is what makes a stale entry detectable: a change
     * anywhere below the child changes that child's hash, and the recorded identities
     * stop matching.
     *
     * Revalidating the subtree is not optional. Dropping it is what a naive child-hash
     * memo does, and it silently serves a parent's previous module after a descendant is
     * edited — including with no request boundary in between, which this loader supports
     * by design (see PhueComponentFreshnessTest).
     *
     * @var array<string, array{identity: string, subtree: array<string, string>, hash: string}>
     */
    private array $childHashMemo = [];

    /**
     * Whether a request boundary has been declared for this loader.
     *
     * Declaring one is the caller saying "this is one unit of work": resolving the same
     * component twice inside it must not change the answer, because the structures it
     * reads are already a per-request snapshot. That is true of the HTTP path, where
     * Application::handle() declares a boundary per request, and of any worker that does
     * the same.
     *
     * Without a declared boundary the loader resolves strictly: every resolution
     * re-confirms the whole branch below the component it is asked about, so a file edited
     * between two resolutions in one long-lived process is still seen. That is what
     * PhueComponentFreshnessTest pins, and it is the only reason the child hash memo is
     * revalidated at all — revalidating costs one stat per node of the subtree, per visit,
     * and a component-heavy page makes about 1,700 of those visits.
     *
     * @var bool
     */
    private bool $boundaryDeclared = false;

    /**
     * Component identity per name, for the length of one top-level resolution.
     *
     * Validating a branch means confirming every node below it, and buildFor() enters a
     * component once per referring edge, so the same subtree was confirmed hundreds of
     * times inside a single resolution. Nothing can change in between: a stat taken
     * microseconds after the last one is the same stat.
     *
     * The scope is one top-level resolution, not one request, because an edit has to be
     * visible to the NEXT resolution (PhueComponentFreshnessTest pins that, and CLI
     * renderers and queue workers have no request boundary to rely on).
     *
     * @var array<string, string|null>
     */
    private array $identityMemo = [];

    /**
     * Resolved file path per component name, recorded whenever a full validated read has
     * already happened. Lets an identity confirmation stat the file directly instead of
     * re-deriving the path through realpath() on every check.
     *
     * @var array<string, string>
     */
    private array $resolvedPaths = [];

    /**
     * Memo of the composable prelude per file, keyed by content hash.
     *
     * The prelude is a pure function of the component's script plus the helper files in
     * jsDir, but computing it runs two regexes over the whole script — measured at
     * 0.028 ms per call, and buildFor() is entered once per referring edge rather than
     * once per component. Only the SHA-1 prefix of the prelude takes part in the client
     * fingerprint, so the digest is what has to be available before the memo check; the
     * full prelude is only needed when code is actually emitted.
     *
     * @var array<string, string|null>
     */
    private array $preludeMemo = [];

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

        // Resolve $jsDir relative to __DIR__ . '/js' without throwing if absent
        $resolvedJsDir = realpath(__DIR__ . '/js');
        if ($resolvedJsDir !== false && is_dir($resolvedJsDir)) {
            $this->jsDir = $resolvedJsDir;
        }

        $this->parser = $parser ?? new PhueTemplateParser($viewsCacheDir);
        $this->persistentCache = $persistentCache;
        $this->compilerBridge = $compilerBridge;
        $this->logger = $logger;
    }

    /**
     * Returns the base directory this loader reads components from.
     *
     * Read-only by contract. A decorating subclass must be constructed with the same
     * directory as the instance it decorates, and the constructor arguments are private,
     * so without this seam a profiler cannot wrap a loader without re-deriving the path
     * by hand and drifting out of sync with the container's own wiring.
     *
     * @return string Absolute path to the component directory.
     */
    public function getComponentsDir(): string
    {
        return $this->componentsDir;
    }

    /**
     * Per-request lifecycle boundary reset.
     * Clears per-request snapshot index and transient raw structure AST caches.
     *
     * @return void
     */
    public function beginRequest(): void
    {
        $this->boundaryDeclared = true;
        $this->componentIndex = null;
        $this->cache = [];
        $this->childHashMemo = [];
        $this->preludeMemo = [];
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

        // No component is being built above this one, so this resolution starts here and
        // every identity it reads is read fresh.
        if ($this->buildingStack === []) {
            $this->identityMemo = [];
        }

        try {
            $structure = $this->structureFor($name);
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

        // Compute prelude and prelude digest early for client fingerprinting
        $scriptContent = trim($structure['script'] ?? '');
        $prelude = $this->preludeFor($filePath, (string) $contentHash, $scriptContent);

        if ($prelude === null) {
            $this->logger?->warning("Failed to build composable prelude for component '{$name}'", [
                'name' => $name,
            ]);
            return null;
        }

        $preludeDigest = $prelude !== '' ? substr(sha1($prelude), 0, 8) : '';

        $children = [];
        $resolvedChildHashes = [];

        if ($this->registry !== null) {
            $children = $this->templateChildrenFor($name, $structure);

            $this->buildingStack[$name] = true;
            try {
                foreach ($children as $child) {
                    $hash = $this->resolveChildHash($child);
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

        // Include $preludeDigest in client fingerprint
        $fingerprint = "{$mtime}_{$size}_{$contentHash}_{$preludeDigest}_" . implode('_', $resolvedChildHashes);

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

        // Append $prelude at the very end
        $code = "{$importStatements}{$renderFnCode}\n"
             . "export const __phueRender = render;\n"
             . "{$proxyBlock}{$scriptContent}{$prelude}";

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

            // Compute prelude and prelude digest for SSR build
            $scriptContent = trim($structure['script'] ?? '');
            $prelude = $this->composablePrelude($scriptContent);

            if ($prelude === null) {
                $this->logger?->warning("Failed to build composable prelude for SSR component '{$name}'", [
                    'name' => $name,
                ]);
                return null;
            }

            $preludeDigest = $prelude !== '' ? substr(sha1($prelude), 0, 8) : '';

            // Include $preludeDigest in SSR fingerprint
            $fingerprint = "{$mtime}_{$size}_{$contentHash}_{$preludeDigest}";

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

            // B4. Append $prelude at the very end
            $code = "{$ssrRenderFnCode}\n"
                  . "export const __phueSsrRender = ssrRender;\n"
                  . "{$proxyBlock}{$scriptContent}{$prelude}";

            $hash = substr(sha1($code), 0, 8);
            $this->ssrMemo[$name] = ['key' => $fingerprint, 'code' => $code, 'hash' => $hash];
            return $code;
        } finally {
            unset($this->buildingStack[$name]);
        }
    }

    /**
     * Internal helper to build a standalone module string given a template and script using the injected compiler bridge.
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

        // Enforce strict fail-loud behavior across all three build entry points.
        // If composablePrelude() returns null (e.g., missing helper, invalid name, or symbol collision),
        // abort module generation immediately rather than silently degrading to an un-preluded broken module.
        $prelude = $this->composablePrelude($scriptContent);
        if ($prelude === null) {
            // Log the shape of the declaration, never the script body: a `.phue`
            // script may embed tokens or other literals, and a build warning
            // routinely lands in a log aggregator that is not access-controlled
            // like the repository.
            $this->logger?->warning("Failed to build composable prelude in buildModuleInstance()", [
                'declaredComposables' => $this->declaredComposableNames($scriptContent),
                'scriptBytes' => strlen($scriptContent),
            ]);
            return '';
        }

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
             . "{$proxyBlock}{$scriptContent}{$prelude}";
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
            style: $structure['style'] ?? null,
            isVueTemplate: true
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

    /**
     * Extracts the raw, unvalidated composable names a script declares.
     *
     * Used purely for diagnostics: it reports what the author wrote, so a build
     * warning points at the offending name without echoing the script body.
     * Unlike composablePrelude() this does not validate or filter the entries.
     *
     * @param string $scriptContent Raw inline component script content.
     * @return list<string>
     */
    private function declaredComposableNames(string $scriptContent): array
    {
        $cleanScript = preg_replace(['/\/\*[\s\S]*?\*\//', '/\/\/.*/'], '', $scriptContent);

        if (!is_string($cleanScript)) {
            return [];
        }

        if (!preg_match('/(?<![A-Za-z0-9_\$])composables\s*:\s*\[([^\]]*)\]/s', $cleanScript, $matches)) {
            return [];
        }

        $items = array_map(
            static fn(string $item): string => trim(trim($item), " \t\n\r\0\x0B'\""),
            explode(',', $matches[1])
        );

        return array_values(array_filter($items, static fn(string $item): bool => $item !== ''));
    }

    /**
     * Private helper to resolve and concatenate JS composable helper modules into a prelude block.
     *
     * @param string $scriptContent Raw inline component script content.
     * @return string|null Empty string if no composables declared, code string if valid, or null on error.
     */
    /**
     * The child component tags declared in a component's template, memoised per file.
     *
     * Keyed by the file's content hash, so a memoised list cannot outlive the content it
     * describes: the template only changes when the content does, and the hash is
     * re-read from the per-request structure entry.
     *
     * @param string $name Component logical name.
     * @param array<string, mixed> $structure Structure as returned by getOrFetchStructure().
     * @return array<int, string>
     */
    private function templateChildrenFor(string $name, array $structure): array
    {
        // Keyed by the component's identity, not by its file path: a component another
        // loader owns has no entry in this loader's structure cache, so a path-keyed memo
        // silently never applied to it and every visit re-scanned its template.
        $identity = $this->identityFor($name);

        if ($identity !== null
            && isset($this->templateChildrenMemo[$name])
            && $this->templateChildrenMemo[$name]['hash'] === $identity
        ) {
            return $this->templateChildrenMemo[$name]['children'];
        }

        $discoverer = new ComponentDiscoverer($this->registry, $this->logger);
        $children = $discoverer->scanTemplate((string) ($structure['template'] ?? ''), $name);

        if ($identity !== null) {
            $this->templateChildrenMemo[$name] = [
                'hash' => $identity,
                'children' => $children,
            ];
        }

        return $children;
    }

    /**
     * Resolve a child component's module hash, reusing a memoised answer when the whole
     * branch below the child is provably unchanged.
     *
     * The memo check is what turns a component-heavy page from rebuilding its dependency
     * graph once per referring edge into a stat per node per visit. It is only sound
     * because a memo entry is trusted ONLY after every recorded identity in the child's
     * subtree still matches: a child's hash is a function of everything below it, so
     * validating the child alone would miss an edited grandchild.
     *
     * @param string $child Child component logical name.
     * @return string|null 8-character hash prefix, or null when resolution fails.
     */
    private function resolveChildHash(string $child): ?string
    {
        $memo = $this->childHashMemo[$child] ?? null;

        // Inside a declared request the branch cannot have changed underneath us: the
        // structures it was confirmed against were themselves read once for this request.
        // Outside one, the branch has to be re-confirmed, because nothing has declared that
        // the structures still describe the files on disk.
        $confirmed = $memo !== null
            && ($this->boundaryDeclared || $this->branchUnchanged($child, $memo['identity'], $memo['subtree']));

        if ($confirmed) {
            return $memo['hash'];
        }

        $hash = $this->registry?->moduleHash($child);

        // A failed resolution is never memoised, so it can never read back as a hit.
        if ($hash === null) {
            return null;
        }

        $identity = $this->identityFor($child);

        if ($identity !== null) {
            $this->childHashMemo[$child] = [
                'identity' => $identity,
                // Only a process that never declared a request boundary has to be able to
                // re-confirm this branch, and only it reads the subtree back. Recording it
                // costs one structure read per node below the child, once per child, on
                // every request — 1,194 of them on a PhUI page.
                'subtree' => $this->boundaryDeclared ? [] : $this->subtreeIdentities($child),
                'hash' => $hash,
            ];
        }

        return $hash;
    }

    /**
     * Whether a component's own identity and every recorded descendant identity still match.
     *
     * Short-circuits on the first difference: a stale branch is detected as soon as the
     * edited file is reached, and an unchanged branch costs one stat per node.
     *
     * @param string $name Component logical name.
     * @param string $identity Identity recorded when the memo entry was written.
     * @param array<string, string> $subtree Recorded identities of the whole branch.
     * @return bool
     */
    private function branchUnchanged(string $name, string $identity, array $subtree): bool
    {
        if ($this->identityFor($name) !== $identity) {
            return false;
        }

        foreach ($subtree as $descendant => $recorded) {
            if ($this->identityFor($descendant) !== $recorded) {
                return false;
            }
        }

        return true;
    }

    /**
     * The identity triple a fingerprint is built from: mtime, size and content hash.
     *
     * Reading it goes through getOrFetchStructure(), which already caches per request and
     * only re-hashes the file when mtime or size moved — so this is a stat in the common
     * case, never a parse.
     *
     * @param string $name Component logical name.
     * @return string|null Identity, or null when the component cannot be read.
     */
    private function preludeFor(string $filePath, string $contentHash, string $scriptContent): ?string
    {
        if ($filePath === '' || $contentHash === '') {
            return $this->composablePrelude($scriptContent);
        }

        $key = $filePath . ':' . $contentHash;

        if (!array_key_exists($key, $this->preludeMemo)) {
            $this->preludeMemo[$key] = $this->composablePrelude($scriptContent);
        }

        return $this->preludeMemo[$key];
    }

    /**
     * A component's structure, revalidated cheaply when its file has not moved.
     *
     * getOrFetchStructure() derives the path through realpath() and flushes the realpath
     * cache on every call — roughly a third of the time buildFor() spends. When this
     * loader already holds a validated entry for the name, an mtime/size comparison against
     * the file is enough to confirm it, and anything different falls through to the full
     * read with its containment checks intact.
     *
     * @param string $name Component logical name.
     * @return array<string, mixed>
     */
    private function structureFor(string $name): array
    {
        $knownPath = $this->resolvedPaths[$name] ?? null;

        if ($knownPath !== null && isset($this->cache[$knownPath])) {
            clearstatcache(true, $knownPath);
            $stat = @stat($knownPath);
            $entry = $this->cache[$knownPath];

            if ($stat !== false
                && (int) $stat['mtime'] === $entry['mtime']
                && (int) $stat['size'] === $entry['size']
            ) {
                $structure = $entry['structure'];
                $structure['filePath'] = $knownPath;

                return $structure;
            }
        }

        $structure = $this->getOrFetchStructure($name);
        $filePath = (string) ($structure['filePath'] ?? '');

        if ($filePath !== '' && isset($this->cache[$filePath])) {
            $this->resolvedPaths[$name] = $filePath;
        }

        return $structure;
    }

    /**
     * The identity of a component, whoever owns it, read once per resolution.
     *
     * A name this loader holds is answered without a dispatch. Anything else is a
     * cross-loader child: this loader cannot hash a file it does not own, and reporting
     * "I cannot confirm" for one made branchUnchanged() call every such edge changed, so
     * the child hash memo was never written for a single PhUI component on an app page.
     */
    private function identityFor(string $name): ?string
    {
        if (array_key_exists($name, $this->identityMemo)) {
            return $this->identityMemo[$name];
        }

        $identity = isset($this->getComponentIndex()[$name])
            ? $this->componentIdentity($name)
            : $this->registry?->componentIdentity($name);

        return $this->identityMemo[$name] = $identity;
    }

    /**
     * A component's structure, whoever owns it.
     */
    private function structureForName(string $name): ?array
    {
        if (isset($this->getComponentIndex()[$name])) {
            try {
                return $this->getOrFetchStructure($name);
            } catch (Throwable) {
                return null;
            }
        }

        return $this->registry?->getStructure($name);
    }

    public function componentIdentity(string $name): ?string
    {
        // Confirmation path. A path recorded here was produced by a getOrFetchStructure()
        // call that already passed the name-format, realpath and containment checks, so
        // re-deriving it on every identity check is pure overhead: realpath() plus the
        // realpath-cache flush it forces costs about 1.75x a plain stat of the same file.
        //
        // This path can only ever CONFIRM that nothing changed. It compares mtime and size
        // against the entry getOrFetchStructure() already validated, and a difference —
        // or a name whose path has never been resolved — falls through to the full read,
        // which re-checks containment and re-hashes the file. A new file's content can
        // therefore never enter the report through this shortcut.
        // A child can belong to another loader in the registry — the PhUI library lives in
        // its own loader, not here. Asking this loader for it raises "component file not
        // found", and the catch costs a full stack trace. On a PhUI-heavy page that was
        // ~1,600 thrown exceptions per request, which is more expensive than every stat
        // in this method combined. Answering "I cannot confirm this name" without raising
        // is both correct and free: the caller falls through to the registry either way,
        // which is the only thing that can resolve a cross-loader child.
        if (!isset($this->getComponentIndex()[$name])) {
            return null;
        }

        $knownPath = $this->resolvedPaths[$name] ?? null;

        if ($knownPath !== null && isset($this->cache[$knownPath])) {
            clearstatcache(true, $knownPath);
            $stat = @stat($knownPath);

            if ($stat !== false) {
                $entry = $this->cache[$knownPath];

                if ((int) $stat['mtime'] === $entry['mtime'] && (int) $stat['size'] === $entry['size']) {
                    return $entry['mtime'] . '_' . $entry['size'] . '_' . ($entry['contentHash'] ?? '');
                }
            }
        }

        try {
            $structure = $this->getOrFetchStructure($name);
        } catch (Throwable) {
            return null;
        }

        $filePath = (string) ($structure['filePath'] ?? '');
        $entry = $this->cache[$filePath] ?? null;

        if ($filePath === '' || $entry === null) {
            return null;
        }

        $this->resolvedPaths[$name] = $filePath;

        return $entry['mtime'] . '_' . $entry['size'] . '_' . ($entry['contentHash'] ?? '');
    }

    /**
     * Record the identity of every node reachable below a component.
     *
     * The child list is already memoised per file, so this is one memo read plus one stat
     * per descendant. Cycles cannot occur here: the traversal carries its own visited set
     * and the caller's build stack has already rejected them.
     *
     * @param string $name Component logical name.
     * @param array<string, bool> $seen Internal visited set.
     * @return array<string, string>
     */
    private function subtreeIdentities(string $name, array $seen = []): array
    {
        $identities = [];

        if (isset($seen[$name])) {
            return $identities;
        }

        $seen[$name] = true;

        $identity = $this->identityFor($name);
        if ($identity === null) {
            return $identities;
        }

        $identities[$name] = $identity;

        if ($this->registry === null) {
            return $identities;
        }

        $structure = $this->structureForName($name);

        if ($structure === null) {
            return $identities;
        }

        $children = $this->templateChildrenFor($name, $structure);

        foreach ($children as $child) {
            foreach ($this->subtreeIdentities($child, $seen) as $descendant => $descendantIdentity) {
                $identities[$descendant] = $descendantIdentity;
            }
        }

        return $identities;
    }

    private function composablePrelude(string $scriptContent): ?string
    {
        if ($this->jsDir === null) {
            return '';
        }

        if (trim($scriptContent) === '') {
            return '';
        }

        // Strip single-line (// ...) and multi-line (/* ... */) JS comments to prevent false matches in comments or strings
        $cleanScript = preg_replace(['/\/\*[\s\S]*?\*\//', '/\/\/.*/'], '', $scriptContent);

        // Strict boundary check: match 'composables:' only as an exact object key property
        if (!preg_match('/(?<![A-Za-z0-9_\$])composables\s*:\s*\[([^\]]*)\]/s', $cleanScript, $matches)) {
            return '';
        }

        $rawList = $matches[1];
        $items = array_map('trim', explode(',', $rawList));
        $names = [];

        foreach ($items as $item) {
            $item = trim($item, " \t\n\r\0\x0B'\"");
            if ($item !== '') {
                // Strict name validation (lowercase, alphanumeric, hyphens)
                if (!preg_match('/^[a-z][a-z0-9\-]*$/', $item)) {
                    $this->logger?->warning("Invalid composable helper name format: '{$item}'");
                    return null;
                }
                $names[] = $item;
            }
        }

        if ($names === []) {
            return '';
        }

        $names = array_values(array_unique($names));
        $preludeBlocks = [];
        $exportedSymbols = [];

        foreach ($names as $name) {
            $targetPath = $this->jsDir . DIRECTORY_SEPARATOR . "{$name}.js";
            $resolvedPath = realpath($targetPath);

            if ($resolvedPath === false || !is_file($resolvedPath)) {
                $this->logger?->warning("Composable helper file missing: '{$name}.js'");
                return null;
            }

            // Path containment check
            if (!str_starts_with($resolvedPath, $this->jsDir . DIRECTORY_SEPARATOR)) {
                $this->logger?->warning("Composable helper file path outside jsDir containment: '{$name}.js'");
                return null;
            }

            $content = file_get_contents($resolvedPath);
            if ($content === false) {
                $this->logger?->warning("Failed to read composable helper file: '{$name}.js'");
                return null;
            }

            // Collect exported function names and check for duplicate exports across composables.
            if (preg_match_all('/^[ \t]*export\s+function\s+([A-Za-z0-9_$]+)/m', $content, $exportMatches)) {
                foreach ($exportMatches[1] as $symbol) {
                    if (isset($exportedSymbols[$symbol])) {
                        $this->logger?->warning("Duplicate export symbol '{$symbol}' detected across composable helpers ('{$exportedSymbols[$symbol]}' and '{$name}')");
                        return null;
                    }
                    $exportedSymbols[$symbol] = $name;
                }
            }

            $preludeBlocks[] = "/* --- Phue JS Helper: {$name}.js --- */\n" . trim($content);
        }

        // Collision check: verify against cleaned script content without commented code blocks
        foreach ($exportedSymbols as $symbol => $sourceComposable) {
            if (preg_match('/(?<![A-Za-z0-9_\$])(?:function|const|let|var|class)\s+' . preg_quote($symbol, '/') . '(?![A-Za-z0-9_\$])/', $cleanScript)) {
                $this->logger?->warning("Name collision detected between component script and composable export symbol '{$symbol}' from '{$sourceComposable}'");
                return null;
            }
        }

        return "\n\n" . implode("\n\n", $preludeBlocks) . "\n";
    }
}
