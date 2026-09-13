<?php

declare(strict_types=1);

namespace Phue\Core\Asset;

use RuntimeException;
use InvalidArgumentException;

/**
 * Manages installing, building, and registering NPM packages using Bun.
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
     * Installs and builds an npm package, updating the manifest atomically.
     *
     * @param string $packageName The name of the npm package.
     * @param string $versionSpec The version specification (e.g., '1.0.0', 'latest').
     * @return void
     * @throws InvalidArgumentException If the package name is invalid.
     * @throws RuntimeException If installation or build fails.
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

        // Clean up empty generated package.json created by bun in root if it only contains empty dependencies
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

        $entryFile = null;
        if (isset($pkgConfig['module']) && is_string($pkgConfig['module'])) {
            $entryFile = $pkgDir . DIRECTORY_SEPARATOR . $pkgConfig['module'];
        } elseif (isset($pkgConfig['exports']['.']['import']) && is_string($pkgConfig['exports']['.']['import'])) {
            $entryFile = $pkgDir . DIRECTORY_SEPARATOR . $pkgConfig['exports']['.']['import'];
        } elseif (isset($pkgConfig['exports']['.']) && is_string($pkgConfig['exports']['.'])) {
            $entryFile = $pkgDir . DIRECTORY_SEPARATOR . $pkgConfig['exports']['.'];
        } elseif (isset($pkgConfig['main']) && is_string($pkgConfig['main'])) {
            $entryFile = $pkgDir . DIRECTORY_SEPARATOR . $pkgConfig['main'];
        }

        if (!$entryFile || !file_exists($entryFile)) {
            throw new RuntimeException(sprintf('Could not determine a valid entry point for package: %s', $packageName));
        }

        $manifest = $this->loadManifest();
        $externals = ['vue'];
        if (isset($manifest['packages']) && is_array($manifest['packages'])) {
            foreach ($manifest['packages'] as $key => $val) {
                $externals[] = is_string($key) ? $key : ($val['name'] ?? null);
            }
        }
        $externals = array_unique(array_filter($externals));

        $externalFlags = '';
        foreach ($externals as $ext) {
            $externalFlags .= ' --external ' . escapeshellarg($ext);
        }

        $outputFileName = str_replace('/', '_', $packageName) . '.esm.js';
        $outputDir = $this->vendorDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $packageName);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $outputFileName;
        $buildCmd = sprintf(
            'bun build %s --target=browser --format=esm --minify%s --outfile=%s 2>&1',
            escapeshellarg($entryFile),
            $externalFlags,
            escapeshellarg($outputPath)
        );

        $buildResult = $this->executeCommand($buildCmd);
        if ($buildResult['code'] !== 0) {
            throw new RuntimeException(sprintf('Failed to build package %s: %s', $packageName, $buildResult['stdout']));
        }

        $integrity = 'sha256-' . base64_encode(hash_file('sha256', $outputPath, true));

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
            'esm_file' => $outputFileName,
            'esm_integrity' => $integrity,
            'css_files' => $cssFiles,
            'installed_at' => gmdate('c'),
        ]);
    }

    /**
     * Removes a previously installed npm package, cleaning up files and manifest.
     *
     * @param string $packageName The name of the package to remove.
     * @return bool True if removed successfully, false if not found.
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