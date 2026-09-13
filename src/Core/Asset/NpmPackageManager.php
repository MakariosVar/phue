<?php

declare(strict_types=1);

namespace Phue\Core\Asset;

use RuntimeException;
use InvalidArgumentException;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * Manages installing, building, and registering NPM packages using Bun with CDN-style structure preservation and rewrite pass.
 */
class NpmPackageManager
{
    private string $manifestPath;
    private string $projectRoot;
    private string $vendorDir;

    /**
     * Initializes the NPM package manager with the project root directory.
     *
     * @param string $projectRoot The project root directory path.
     */
    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $this->manifestPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'phue-packages.json';
        $this->vendorDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'vendor';
    }

    /**
     * Installs an npm package by copying its ESM tree, executing a rewrite pass for extension-less specifiers,
     * discovering subpaths, and updating the manifest.
     *
     * @param string $packageName The name of the npm package.
     * @param string $versionSpec The version specification (e.g., '1.0.0', 'latest').
     * @return void
     * @throws InvalidArgumentException If the package name is invalid.
     * @throws RuntimeException If installation fails.
     */
    public function install(string $packageName, string $versionSpec = 'latest'): void
    {
        $npmNameRegex = '/^(?:@[a-z0-9-*~][a-z0-9-*._~]*\/)?[a-z0-9-~][a-z0-9-._~]*$/';
        if (!preg_match($npmNameRegex, $packageName)) {
            throw new InvalidArgumentException(sprintf('Invalid npm package name: "%s"', $packageName));
        }

        $safePkg = escapeshellarg($packageName . '@' . $versionSpec);
        $installCmd = sprintf('bun install %s --no-save 2>&1', $safePkg);

        $result = $this->executeCommand($installCmd);
        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf('Failed to install package %s: %s', $packageName, $result['stdout']));
        }

        $generatedPackageJson = $this->projectRoot . DIRECTORY_SEPARATOR . 'package.json';
        if (file_exists($generatedPackageJson)) {
            $content = file_get_contents($generatedPackageJson);
            $decoded = json_decode($content, true);
            if (is_array($decoded) && count($decoded) <= 1 && (!isset($decoded['dependencies']) || empty($decoded['dependencies']))) {
                @unlink($generatedPackageJson);
            }
        }

        $pkgDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $packageName);
        $pkgJsonPath = $pkgDir . DIRECTORY_SEPARATOR . 'package.json';

        if (!file_exists($pkgJsonPath)) {
            throw new RuntimeException(sprintf('Package json not found for installed package: %s', $packageName));
        }

        $pkgConfig = json_decode(file_get_contents($pkgJsonPath), true);
        if (!is_array($pkgConfig)) {
            throw new RuntimeException(sprintf('Invalid package.json for package: %s', $packageName));
        }

        $exactVersion = $pkgConfig['version'] ?? $versionSpec;

        // Enhanced heuristic ESM root detection (checks exports, module, main, or nested 'esm/' directory existence)
        $esmSubDir = '';
        if (isset($pkgConfig['exports']['.']['import']) && is_string($pkgConfig['exports']['.']['import'])) {
            $esmSubDir = dirname($pkgConfig['exports']['.']['import']);
        } elseif (isset($pkgConfig['module']) && is_string($pkgConfig['module'])) {
            $esmSubDir = dirname($pkgConfig['module']);
        } elseif (is_dir($pkgDir . DIRECTORY_SEPARATOR . 'esm') && file_exists($pkgDir . DIRECTORY_SEPARATOR . 'esm' . DIRECTORY_SEPARATOR . 'index.js')) {
            $esmSubDir = 'esm';
        } elseif (isset($pkgConfig['main']) && is_string($pkgConfig['main'])) {
            $esmSubDir = dirname($pkgConfig['main']);
        }

        $sourceEsmDir = $pkgDir;
        if ($esmSubDir !== '' && $esmSubDir !== '.' && is_dir($pkgDir . DIRECTORY_SEPARATOR . $esmSubDir)) {
            $sourceEsmDir = $pkgDir . DIRECTORY_SEPARATOR . $esmSubDir;
        }

        $outputDir = $this->vendorDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $packageName);
        if (is_dir($outputDir)) {
            $this->recursiveDelete($outputDir);
        }
        mkdir($outputDir, 0755, true);

        // 1. Mirror source tree
        $this->recursiveCopy($sourceEsmDir, $outputDir);

        // 2. Rewrite pass for relative extension-less specifiers in all copied .js files (using single-quoted strings to preserve \3 backreference)
        $this->executeRewritePass($outputDir);

        // 3. Scan for exact subpaths and flat files without double vendoring
        $subpaths = $this->scanSubpaths($outputDir, $packageName);

        // Determine main entry file relative path
        $mainEntryRelative = 'index.js';
        if (isset($pkgConfig['module']) && is_string($pkgConfig['module'])) {
            $mainEntryRelative = basename($pkgConfig['module']);
        } elseif (isset($pkgConfig['exports']['.']['import']) && is_string($pkgConfig['exports']['.']['import'])) {
            $mainEntryRelative = basename($pkgConfig['exports']['.']['import']);
        } elseif (file_exists($outputDir . DIRECTORY_SEPARATOR . 'index.js')) {
            $mainEntryRelative = 'index.js';
        }

        if (!file_exists($outputDir . DIRECTORY_SEPARATOR . $mainEntryRelative)) {
            $mainEntryRelative = 'index.js';
        }

        $cssFiles = [];
        if (isset($pkgConfig['style']) && is_string($pkgConfig['style'])) {
            $cssSource = $pkgDir . DIRECTORY_SEPARATOR . $pkgConfig['style'];
            if (file_exists($cssSource)) {
                $cssFileName = str_replace('/', '_', $packageName) . '.css';
                $cssOutputPath = $outputDir . DIRECTORY_SEPARATOR . $cssFileName;
                copy($cssSource, $cssOutputPath);
                $cssFiles[] = '/assets/vendor/' . $packageName . '/' . $cssFileName;
            }
        }

        $this->updateManifest($packageName, [
            'version' => $exactVersion,
            'resolved_from' => $versionSpec,
            'main' => '/assets/vendor/' . $packageName . '/' . $mainEntryRelative,
            'subpaths' => $subpaths,
            'css_files' => $cssFiles,
            'installed_at' => gmdate('c'),
        ]);
    }

    /**
     * Executes the rewrite pass on all copied .js files to add .js extensions to relative extension-less imports.
     *
     * @param string $dir The directory path containing the built JavaScript files.
     * @return void
     */
    private function executeRewritePass(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $filePath = $file->getPathname();
                $content = file_get_contents($filePath);
                if ($content === false) {
                    continue;
                }

                // Rewrite relative extension-less specifiers in import/export using single quotes to avoid octal interpretation of \\3
                $updated = preg_replace_callback(
                    "/\b(import|export)\s+([^;\n]+?\s+from\s+|[\"'])([\"'])(?!https?:\/\/|\/|\.?\.?\/[^\n]*\.[a-z0-9]+['\"])([\.\/][^;\n]*?)\\3/u",
                    static function ($matches) {
                        $quote = $matches[3];
                        $specPath = $matches[4];

                        if (preg_match('/\.(js|mjs|cjs|json|css)$/i', $specPath)) {
                            return $matches[0];
                        }

                        return str_replace($quote . $specPath . $quote, $quote . $specPath . '.js' . $quote, $matches[0]);
                    },
                    $content
                );

                // Handle dynamic import() statements using single quotes
                $updated = preg_replace_callback(
                    "/\bimport\s*\(\s*(['\"])(?!https?:\/\/|\/|\.?\.?\/[^\n]*\.[a-z0-9]+['\"])([\.\/][^\(\)]*?)\\1\s*\)/u",
                    static function ($matches) {
                        $quote = $matches[1];
                        $specPath = $matches[2];

                        if (preg_match('/\.(js|mjs|cjs|json|css)$/i', $specPath)) {
                            return $matches[0];
                        }

                        return str_replace($quote . $specPath . $quote, $quote . $specPath . '.js' . $quote, $matches[0]);
                    },
                    $updated ?? $content
                );

                if ($updated !== null && $updated !== $content) {
                    file_put_contents($filePath, $updated);
                }
            }
        }
    }

    /**
     * Scans the package vendor directory for exact subpaths (directories containing index.js and flat files like loose JS files).
     *
     * @param string $vendorPkgDir The directory path of the vendor package.
     * @param string $packageName The name of the npm package.
     * @return array<string, string> Map of client-relative subpath keys to correct asset URLs without double vendoring.
     */
    private function scanSubpaths(string $vendorPkgDir, string $packageName): array
    {
        $subpaths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($vendorPkgDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $baseLen = strlen($vendorPkgDir);

        foreach ($iterator as $item) {
            $pathName = $item->getPathname();
            $relPath = ltrim(substr($pathName, $baseLen), DIRECTORY_SEPARATOR);
            if ($relPath === '') {
                continue;
            }
            $normalizedRelPath = str_replace(DIRECTORY_SEPARATOR, '/', $relPath);

            if ($item->isDir()) {
                $indexPath = $pathName . DIRECTORY_SEPARATOR . 'index.js';
                if (file_exists($indexPath)) {
                    // Client-relative subpath key without esm/ prefix, URL correctly points to /assets/vendor/{packageName}/...
                    $subpaths[$normalizedRelPath] = '/assets/vendor/' . $packageName . '/' . $normalizedRelPath . '/index.js';
                }
            } elseif ($item->isFile() && $item->getExtension() === 'js') {
                // Include flat files (e.g. locale/el.js) as subpath entries if they are not root index.js
                if ($normalizedRelPath !== 'index.js' && !str_ends_with($normalizedRelPath, '/index.js')) {
                    $keyWithoutExt = preg_replace('/\.js$/i', '', $normalizedRelPath);
                    if ($keyWithoutExt !== null && $keyWithoutExt !== '') {
                        $subpaths[$keyWithoutExt] = '/assets/vendor/' . $packageName . '/' . $normalizedRelPath;
                    }
                }
            }
        }

        return $subpaths;
    }

    /**
     * Removes a previously installed npm package, cleaning up files and manifest.
     *
     * @param string $packageName The name of the package to remove.
     * @return bool True if removed successfully, false if not found.
     * @throws InvalidArgumentException If the package name is invalid.
     */
    public function remove(string $packageName): bool
    {
        $npmNameRegex = '/^(?:@[a-z0-9-*~][a-z0-9-*._~]*\/)?[a-z0-9-~][a-z0-9-._~]*$/';
        if (!preg_match($npmNameRegex, $packageName)) {
            throw new InvalidArgumentException(sprintf('Invalid npm package name: "%s"', $packageName));
        }

        $manifest = $this->loadManifest();
        if (!isset($manifest['packages'][$packageName])) {
            return false;
        }

        unset($manifest['packages'][$packageName]);

        $globalCss = [];
        foreach ($manifest['packages'] as $pkg) {
            if (!empty($pkg['css_files']) && is_array($pkg['css_files'])) {
                foreach ($pkg['css_files'] as $css) {
                    $globalCss[] = $css;
                }
            }
        }
        $manifest['css_files'] = array_values(array_unique($globalCss));

        $this->saveManifestAtomic($manifest);

        $pkgVendorDir = $this->vendorDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $packageName);
        if (is_dir($pkgVendorDir)) {
            $this->recursiveDelete($pkgVendorDir);
        }

        return true;
    }

    /**
     * Lists all installed npm packages from the manifest.
     *
     * @return array<string, array<string, mixed>> List of installed packages.
     */
    public function listPackages(): array
    {
        $manifest = $this->loadManifest();
        return $manifest['packages'] ?? [];
    }

    /**
     * Restores/reinstalls all packages registered in the manifest.
     *
     * @return void
     */
    public function restore(): void
    {
        $manifest = $this->loadManifest();
        $packages = $manifest['packages'] ?? [];

        foreach ($packages as $packageName => $data) {
            $versionSpec = $data['resolved_from'] ?? 'latest';
            $this->install($packageName, $versionSpec);
        }
    }

    /**
     * Executes a system command via proc_open and returns output/code.
     *
     * @param string $cmd The command string to execute.
     * @return array{code: int, stdout: string}
     */
    private function executeCommand(string $cmd): array
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $this->projectRoot);
        if (!is_resource($process)) {
            return [
                'code' => -1,
                'stdout' => 'Failed to execute proc_open.',
            ];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => $stdout !== '' ? $stdout : $stderr,
        ];
    }

    /**
     * Loads the package manifest file.
     *
     * @return array<string, mixed> The manifest data structure.
     */
    private function loadManifest(): array
    {
        if (!file_exists($this->manifestPath)) {
            return ['packages' => [], 'css_files' => []];
        }

        $content = file_get_contents($this->manifestPath);
        if ($content === false) {
            return ['packages' => [], 'css_files' => []];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : ['packages' => [], 'css_files' => []];
    }

    /**
     * Updates the package manifest atomically with newly installed package info.
     *
     * @param string $packageName The name of the package.
     * @param array<string, mixed> $packageData Metadata configuration of the package.
     * @return void
     */
    private function updateManifest(string $packageName, array $packageData): void
    {
        $manifest = $this->loadManifest();

        if (!isset($manifest['packages']) || !is_array($manifest['packages'])) {
            $manifest['packages'] = [];
        }

        $manifest['packages'][$packageName] = $packageData;

        $globalCss = [];
        foreach ($manifest['packages'] as $pkg) {
            if (!empty($pkg['css_files']) && is_array($pkg['css_files'])) {
                foreach ($pkg['css_files'] as $css) {
                    $globalCss[] = $css;
                }
            }
        }
        $manifest['css_files'] = array_values(array_unique($globalCss));

        $this->saveManifestAtomic($manifest);
    }

    /**
     * Saves the manifest file atomically using a temporary file and rename.
     *
     * @param array<string, mixed> $manifest The manifest array data.
     * @return void
     * @throws RuntimeException If writing or renaming the temporary manifest file fails.
     */
    private function saveManifestAtomic(array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tempFile = $this->manifestPath . '.' . uniqid('', true) . '.tmp';

        if (file_put_contents($tempFile, $json) === false) {
            throw new RuntimeException('Failed to write temporary manifest file.');
        }

        if (!rename($tempFile, $this->manifestPath)) {
            @unlink($tempFile);
            throw new RuntimeException('Failed to atomically update package manifest.');
        }
    }

    /**
     * Recursively copies files from source to destination directory.
     *
     * @param string $source The source directory path.
     * @param string $dest The destination directory path.
     * @return void
     */
    private function recursiveCopy(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $subPath = $iterator->getSubPathName();
            $targetPath = $dest . DIRECTORY_SEPARATOR . $subPath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    /**
     * Recursively deletes a directory.
     *
     * @param string $dir The directory path.
     * @return void
     */
    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }

        rmdir($dir);
    }
}