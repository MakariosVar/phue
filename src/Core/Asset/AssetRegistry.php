<?php

declare(strict_types=1);

namespace Phue\Core\Asset;

use InvalidArgumentException;
use ValueError;

/**
 * Manages asset sources and registers global/route-level JS & CSS resources.
 *
 * @package Phue\Core\Asset
 */
class AssetRegistry
{
    /** @var array<string, string> URL prefix => system base directory path map */
    private array $sources = [];

    /** @var array<string, callable> URL prefix => resolver callback map */
    private array $resolvers = [];

    /**
     * Globally registered JavaScript specifications.
     *
     * These scripts are rendered for every HTML shell produced by the registry.
     *
     * @var array<int, array{url: string, isModule: bool}>
     */
    private array $globalScripts = [];

    /**
     * JavaScript specifications registered for the current request.
     *
     * This pool is cleared by {@see beginRequest()}.
     *
     * @var array<int, array{url: string, isModule: bool}>
     */
    private array $requestScripts = [];

    /** @var array<int, string> Registered CSS Stylesheet URLs */
    private array $styles = [];

    /**
     * Map a URL prefix to a dynamic resolver callback.
     *
     * @param string $urlPrefix e.g. '/assets/phue/'
     * @param callable(string): ?string $resolver Callback receiving the relative path and returning content or null
     * @return self
     */
    public function addResolver(string $urlPrefix, callable $resolver): self
    {
        $normalizedPrefix = '/' . trim($urlPrefix, '/');
        $this->resolvers[$normalizedPrefix] = $resolver;

        // Sort by longest prefix first
        uksort($this->resolvers, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $this;
    }

    /**
     * Resolve a dynamic resource via registered resolvers.
     *
     * @param string $uri
     * @return string|null Resolved dynamic content or null if not handled
     */
    public function resolveDynamic(string $uri): ?string
    {
        if (str_contains($uri, "\0")) {
            return null;
        }

        $path = parse_url($uri, PHP_URL_PATH) ?? $uri;

        foreach ($this->resolvers as $prefix => $resolver) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $relativePath = ltrim(substr($path, strlen($prefix)), '/');
                $result = $resolver($relativePath);

                if (is_string($result)) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Map a URL prefix to a real filesystem directory. Sorts by prefix length descending for accurate matching.
     *
     * @param string $urlPrefix e.g. '/assets/vendor'
     * @param string $directory Absolute filesystem path
     * @return self
     * @throws InvalidArgumentException If directory does not exist
     */
    public function registerSource(string $urlPrefix, string $directory): self
    {
        $realPath = realpath($directory);

        if ($realPath === false || !is_dir($realPath)) {
            throw new InvalidArgumentException("Asset source directory does not exist: {$directory}");
        }

        $normalizedPrefix = '/' . trim($urlPrefix, '/');
        $this->sources[$normalizedPrefix] = $realPath;

        // Longest-prefix matching priority: sort prefixes by string length descending
        uksort($this->sources, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $this;
    }

    /**
     * Add a JavaScript file URL to the HTML rendering queue.
     * Checks against both per-request and global pools to prevent duplicate inclusions.
     *
     * @param string $url
     * @param bool $isModule Set to true if the script should be rendered with type="module"
     * @return self
     */
    public function addScript(string $url, bool $isModule = false): self
    {
        foreach ($this->globalScripts as $script) {
            if ($script['url'] === $url) {
                return $this;
            }
        }

        foreach ($this->requestScripts as $script) {
            if ($script['url'] === $url) {
                return $this;
            }
        }

        $this->requestScripts[] = [
            'url' => $url,
            'isModule' => $isModule,
        ];

        return $this;
    }

    /**
     * Add a JavaScript file URL to the global rendering queue.
     *
     * Global scripts survive calls to {@see beginRequest()} and are included
     * in every result returned by {@see getScripts()}.
     *
     * This is intended for application-wide dependencies such as the Phue
     * explorer/bootstrap script.
     *
     * @param string $url
     * @param bool $isModule Set to true if the script should be rendered with type="module"
     * @return self
     */
    public function addGlobalScript(string $url, bool $isModule = false): self
    {
        foreach ($this->globalScripts as $script) {
            if ($script['url'] === $url) {
                return $this;
            }
        }

        $this->globalScripts[] = [
            'url' => $url,
            'isModule' => $isModule,
        ];

        return $this;
    }

    /**
     * Add a CSS stylesheet URL to the HTML rendering queue.
     *
     * Styles remain registered on the registry and are not affected by
     * {@see beginRequest()}.
     *
     * @param string $url
     * @return self
     */
    public function addStyle(string $url): self
    {
        if (!in_array($url, $this->styles, true)) {
            $this->styles[] = $url;
        }

        return $this;
    }

    /**
     * Resolve a requested URI to a physical file path if it matches a registered source prefix.
     * Protects against Path Traversal (including Sibling Dir Exploits) & Null Byte ValueErrors.
     *
     * @param string $uri
     * @return string|null Resolved real absolute path or null if not found
     */
    public function resolvePath(string $uri): ?string
    {
        if (str_contains($uri, "\0")) {
            return null;
        }

        $path = parse_url($uri, PHP_URL_PATH) ?? $uri;

        foreach ($this->sources as $prefix => $baseDir) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $relativePath = ltrim(substr($path, strlen($prefix)), '/');

                try {
                    $targetPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                    $realTarget = realpath($targetPath);
                    $realBase = realpath($baseDir);

                    if ($realTarget === false || $realBase === false) {
                        continue;
                    }

                    $isExactMatch = ($realTarget === $realBase);
                    $isUnderneath = str_starts_with($realTarget, $realBase . DIRECTORY_SEPARATOR);

                    if (($isExactMatch || $isUnderneath) && is_file($realTarget)) {
                        return $realTarget;
                    }
                } catch (ValueError) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Check if a given URI starts with any registered static source prefix.
     *
     * @param string $uri
     * @return bool
     */
    public function matchesSourcePrefix(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? $uri;

        foreach ($this->sources as $prefix => $baseDir) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Begin a new request asset collection.
     *
     * Global scripts are preserved, while all scripts registered for the
     * previous request are discarded.
     *
     * This should be called once at the beginning of each request before
     * request-specific scripts are registered.
     *
     * @return self
     */
    public function beginRequest(): self
    {
        $this->requestScripts = [];

        return $this;
    }

    /**
     * Return only scripts registered for the current request.
     *
     * Global scripts are intentionally excluded from this result.
     *
     * @return array<int, array{url: string, isModule: bool}>
     */
    public function getRequestScripts(): array
    {
        return $this->requestScripts;
    }

    /**
     * Return all scripts that should be rendered.
     *
     * Global scripts are returned first, followed by scripts registered for
     * the current request.
     *
     * @return array<int, array{url: string, isModule: bool}>
     */
    public function getScripts(): array
    {
        return array_merge(
            $this->globalScripts,
            $this->requestScripts
        );
    }

    /**
     * Return globally registered scripts.
     *
     * @return array<int, array{url: string, isModule: bool}>
     */
    public function getGlobalScripts(): array
    {
        return $this->globalScripts;
    }

    /**
     * Return registered CSS stylesheet URLs.
     *
     * @return array<int, string>
     */
    public function getStyles(): array
    {
        return $this->styles;
    }

    /**
     * Return registered static asset sources.
     *
     * @return array<string, string>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * Return registered dynamic asset resolvers.
     *
     * @return array<string, callable>
     */
    public function getResolvers(): array
    {
        return $this->resolvers;
    }
}