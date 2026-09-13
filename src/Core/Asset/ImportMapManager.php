<?php

declare(strict_types=1);

namespace Phue\Core\Asset;

/**
 * Manages import maps and stylesheet links based on the phue-packages.json manifest.
 */
class ImportMapManager
{
    private string $manifestPath;

    /**
     * @param string $basePath The project root directory path.
     */
    public function __construct(string $basePath)
    {
        $this->manifestPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'phue-packages.json';
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
        $imports = [
            'vue' => '/assets/vendor/vue3/vue.runtime.esm-browser.js',
        ];

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
                if (!$name) {
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