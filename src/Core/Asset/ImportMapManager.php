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
     * Generates the import map array.
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

        // Expected manifest structure expectation: packages or items mapping
        // e.g., packages: [ {"name": "@vue/reactivity", "esm_file": "x.esm.js"}, ... ] 
        // or key-value pairs depending on structure. Let's support a flexible robust structure.
        $packages = $data['packages'] ?? $data;

        if (is_array($packages)) {
            foreach ($packages as $pkgName => $pkgData) {
                // Handle cases where package name is key or property
                $name = is_string($pkgName) ? $pkgName : ($pkgData['name'] ?? null);
                $esmFile = $pkgData['esm_file'] ?? (is_string($pkgName) ? $pkgData : null);

                if (!$name || !$esmFile) {
                    continue;
                }

                // Clean and normalize package path safely (preventing path traversal while allowing scoped packages like @vue/reactivity)
                $safeName = ltrim($name, '/');
                if (str_contains($safeName, '..')) {
                    continue; // Disallow path traversal attempts
                }

                $imports[$name] = "/assets/vendor/{$safeName}/{$esmFile}";
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