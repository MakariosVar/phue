<?php

declare(strict_types=1);

namespace Phue\Core\Asset;

/**
 * Manages import maps and stylesheet links based on the phue-packages.json manifest.
 */
class ImportMapManager
{
    private string $vendorRoot;
    private ?string $resolvedVueUrl = null;
    private bool $vueResolutionAttempted = false;
    private string $manifestPath;
    private bool $debug;

    /**
     * @param string $basePath The project root directory path (holds phue-packages.json).
     * @param bool $debug Serve the Vue development build instead of the production build.
     * @param string|null $vendorRoot Directory holding the assets the vendor URLs resolve to. Defaults to "<basePath>/storage" when null.
     */
    public function __construct(string $basePath, bool $debug = false, ?string $vendorRoot = null)
    {
        $base = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->manifestPath = $base . DIRECTORY_SEPARATOR . 'phue-packages.json';
        $this->debug = $debug;
        $this->vendorRoot = $vendorRoot !== null 
            ? rtrim($vendorRoot, DIRECTORY_SEPARATOR) 
            : $base . DIRECTORY_SEPARATOR . 'storage';
    }

    /**
     * Generates the import map array supporting main entries and subpaths.
     * 
     * Decision on missing manifest: If the manifest file does not exist, 
     * it gracefully falls back to returning only the core 'vue' mapping.
     * 
     * @return array{imports: array<string, string>}
     * @throws \RuntimeException If the manifest contains malformed JSON (data corruption).
     */
    public function getImportMap(): array
    {
        $imports = [];

        // Resolve core 'vue' mapping first so it's present even if manifest is missing
        $vueUrl = $this->resolveVueUrl();
        if ($vueUrl !== null) {
            $imports['vue'] = $vueUrl;
        }

        // The framework runtime. Reserved the same way as 'vue': a package
        // manifest must never be able to redefine what `createPhueApp` and
        // `t` resolve to, because every built .phue module imports from here.
        $imports['phue'] = $this->resolveRuntimeUrl();

        if (!file_exists($this->manifestPath)) {
            return ['imports' => $imports];
        }

        $content = file_get_contents($this->manifestPath);
        if ($content === false) {
            return ['imports' => $imports];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Manifest file is corrupted or contains malformed JSON: ' . json_last_error_msg());
        }

        $packages = $data['packages'] ?? $data;

        if (is_array($packages)) {
            foreach ($packages as $pkgName => $pkgData) {
                $name = is_string($pkgName) ? $pkgName : ($pkgData['name'] ?? null);
                if (!$name || $name === 'vue' || $name === 'phue') {
                    // Explicitly guard against manifest overwriting the core
                    // 'vue' and 'phue' mappings
                    continue;
                }

                $safeName = ltrim($name, '/');
                if (str_contains($safeName, '..')) {
                    continue;
                }

                // Consume main entry instead of esm_file
                if (isset($pkgData['main']) && is_string($pkgData['main'])) {
                    $imports[$name] = $pkgData['main'];
                }

                // Consume subpaths contract (e.g. package/subpath -> URL mapping)
                if (isset($pkgData['subpaths']) && is_array($pkgData['subpaths'])) {
                    foreach ($pkgData['subpaths'] as $subpathKey => $subpathUrl) {
                        $subpathKeyClean = ltrim($subpathKey, '/');
                        if (str_contains($subpathKeyClean, '..')) {
                            continue;
                        }
                        $imports[$name . '/' . $subpathKeyClean] = $subpathUrl;
                    }
                }
            }
        }

        return ['imports' => $imports];
    }

    /**
     * Resolves the Vue URL based on disk availability and debug mode.
     * Results are memoized using $vueResolutionAttempted as a sentinel for null values.
     */
    private function resolveVueUrl(): ?string
    {
        if ($this->vueResolutionAttempted) {
            return $this->resolvedVueUrl;
        }

        $this->vueResolutionAttempted = true;

        $devRel = '/assets/vendor/vue3/vue.runtime.esm-browser.js';
        $prodRel = '/assets/vendor/vue3/vue.runtime.esm-browser.prod.js';

        $preferredRel = $this->debug ? $devRel : $prodRel;
        $fallbackRel  = $this->debug ? $prodRel : $devRel;

        if (file_exists($this->vendorRoot . $preferredRel)) {
            $this->resolvedVueUrl = $preferredRel;
        } elseif (file_exists($this->vendorRoot . $fallbackRel)) {
            $this->resolvedVueUrl = $fallbackRel;
        } else {
            $this->resolvedVueUrl = null;
        }

        return $this->resolvedVueUrl;
    }

    /**
     * The URL the bare `phue` specifier resolves to.
     *
     * The runtime module imports `./i18n.js` as a sibling, so the URL must
     * point at a directory whose relative imports are servable — which is why
     * it is published under /assets/runtime/ alongside its dependency rather
     * than being inlined into a page.
     */
    private function resolveRuntimeUrl(): string
    {
        return '/assets/runtime/createPhueApp.js';
    }

    /**
     * Renders the HTML <script type="importmap"> tag.
     */
    public function renderImportMapTag(): string
    {
        $map = $this->getImportMap();
        $json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return sprintf('<script type="importmap">%s</script>', PHP_EOL . $json . PHP_EOL);
    }

    /**
     * Renders <link rel="stylesheet"> tags for each CSS file entry in the manifest.
     */
    public function renderStylesheetLinks(): string
    {
        if (!file_exists($this->manifestPath)) {
            return '';
        }

        $content = file_get_contents($this->manifestPath);
        if ($content === false) {
            return '';
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['css_files']) || !is_array($data['css_files'])) {
            return '';
        }

        $links = [];
        foreach ($data['css_files'] as $cssFile) {
            $safeFile = htmlspecialchars((string) $cssFile, ENT_QUOTES, 'UTF-8');
            $links[] = sprintf('<link rel="stylesheet" href="%s">', $safeFile);
        }

        return implode(PHP_EOL, $links);
    }
}