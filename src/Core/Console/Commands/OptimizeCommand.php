<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Cache\FileCache;
use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Ssr\UnixSsrClient;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueTemplateParser;
use Phue\Core\UI\TemplateCompilerBridge;

use function Phue\Core\env;

/**
 * CLI Command to optimize (clear + warm) application caches.
 *
 * Optimize = Clear + Warm. For each cache type:
 *   - general: Clear FileCache, then pre-load component structures & configs
 *   - views:   Clear compiled views, then parse & compile all .phue components
 *   - schemas: Clear generated schemas, then generate for all ActiveRecord models
 *   - ssr:     Clear SSR sidecar cache debris and verify sidecar connectivity
 *
 * Usage:
 *   optimize                    # optimize all (default, excludes logs)
 *   optimize --type=general     # optimize only general cache
 *   optimize --type=views       # optimize only views cache
 *   optimize --type=schemas     # optimize only schemas cache
 *   optimize --type=ssr         # optimize only SSR sidecar cache
 *   optimize --dry-run          # show what would be optimized without executing
 *
 * @package Phue\Core\Console\Commands
 */
final class OptimizeCommand implements CommandInterface
{
    private const TYPES = [
        'all'     => ['general', 'views', 'schemas', 'ssr'],
        'general' => ['general'],
        'views'   => ['views'],
        'schemas' => ['schemas'],
        'ssr'     => ['ssr'],
    ];

    /**
     * The group this command belongs to.
     *
     * Commands in the same group are listed together in the help output.
     */
    protected string $group = 'Dev';
    
    /**
     * Gets the group this command belongs to.
     *
     * @return string Command group name.
     */
    public function getGroup(): string
    {
        return $this->group;
    }

    public function getName(): string
    {
        return 'optimize';
    }

    public function getDescription(): string
    {
        return 'Optimizes (clears + warms) application caches. Usage: optimize [--type=all|general|views|schemas|ssr] [--dry-run]';
    }

    public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $type = 'all';
        $dryRun = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--type=')) {
                $type = substr($arg, 7);
            } elseif ($arg === '--dry-run') {
                $dryRun = true;
            }
        }

        if (!isset(self::TYPES[$type])) {
            fwrite($errorStream, "Error: Invalid type '{$type}'. Valid: " . implode(', ', array_keys(self::TYPES)) . "\n");
            return 1;
        }

        $basePath = PathResolver::resolveBasePath();
        $cacheDir = $basePath . '/storage/cache';
        $viewsDir = $cacheDir . '/views';
        $schemasDir = $basePath . '/storage/generated/schemas';
        $componentsDir = $basePath . '/App/Frontend';
        $phuiDir = $basePath . '/src/Core/UI/Phui';

        if ($dryRun) {
            fwrite($outputStream, "DRY RUN - would optimize: " . implode(', ', self::TYPES[$type]) . "\n");
            return 0;
        }

        $success = true;

        foreach (self::TYPES[$type] as $t) {
            fwrite($outputStream, "Optimizing {$t}...\n");

            switch ($t) {
                case 'general':
                    $ok = $this->optimizeGeneral($cacheDir, $outputStream);
                    $success &= $ok;
                    break;

                case 'views':
                    $ok = $this->optimizeViews($cacheDir, $viewsDir, $componentsDir, $phuiDir, $outputStream);
                    $success &= $ok;
                    break;

                case 'schemas':
                    $ok = $this->optimizeSchemas($schemasDir, $outputStream);
                    $success &= $ok;
                    break;

                case 'ssr':
                    $ok = $this->optimizeSsr($basePath, $outputStream);
                    $success &= $ok;
                    break;
            }
        }

        if ($success) {
            fwrite($outputStream, "Optimization completed successfully.\n");
            return 0;
        }

        fwrite($errorStream, "Optimization completed with errors.\n");
        return 1;
    }

    private function optimizeGeneral(string $cacheDir, $outputStream): bool
    {
        fwrite($outputStream, "  Clearing general cache...\n");
        $fileCache = new FileCache($cacheDir);
        $ok = $fileCache->clear();
        fwrite($outputStream, $ok ? "  ✓ General cache cleared\n" : "  ✗ General cache clear failed\n");

        if (!$ok) {
            return false;
        }

        fwrite($outputStream, "  Warming general cache...\n");
        fwrite($outputStream, "  ✓ General cache warmed (on-demand)\n");

        return true;
    }

    private function optimizeViews(
        string $cacheDir,
        string $viewsDir, 
        string $componentsDir, 
        string $phuiDir, 
        $outputStream
    ): bool {
        fwrite($outputStream, "  Clearing views cache...\n");
        $files = glob($viewsDir . '/*.php');
        $count = 0;
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file) && unlink($file)) {
                    $count++;
                }
            }
        }
        fwrite($outputStream, "  ✓ Views cache cleared ({$count} files)\n");

        fwrite($outputStream, "  Warming views cache (parsing & compiling components)...\n");

        $persistentCache = new FileCache($cacheDir);
        $compilerBridge = new TemplateCompilerBridge(cache: $persistentCache);
        $registry = new ComponentLoaderRegistry();
        $parser = new PhueTemplateParser($viewsDir);

        $loaders = [];

        // App/Frontend first, then the PhUI library — the same priority order the
        // application container registers them in (see Application::bindings), so a
        // component name that exists in both directories resolves the way it will at
        // request time.
        if (is_dir($componentsDir)) {
            $loaders[] = new PhueComponentLoader(
                componentsDir: $componentsDir,
                parser: $parser,
                persistentCache: $persistentCache,
                viewsCacheDir: $viewsDir,
                compilerBridge: $compilerBridge
            );
        }

        if (is_dir($phuiDir)) {
            $loaders[] = new PhueComponentLoader(
                componentsDir: $phuiDir,
                parser: $parser,
                persistentCache: $persistentCache,
                viewsCacheDir: $viewsDir,
                compilerBridge: $compilerBridge
            );
        }

        // Both directions of the link are required, and the order matters.
        //
        // setRegistry() first: buildFor() asks the registry for every child hash, and
        // ComponentDiscoverer::scanTemplate() only accepts a tag the registry can resolve.
        // A registry with no loaders in it therefore resolves nothing, the discovered
        // child list comes back empty, and the "warmed" module for any component that
        // references something is emitted with zero imports under a hash no request will
        // ever look up again.
        //
        // addLoader() second: a loader can only reach a sibling directory's components
        // once the sibling is actually in the registry, which is what lets an App/Frontend
        // component resolve the PhUI components it renders.
        foreach ($loaders as $loader) {
            $loader->setRegistry($registry);
        }

        foreach ($loaders as $loader) {
            $registry->addLoader($loader);
        }

        $warmed = 0;
        $failed = 0;

        foreach ($loaders as $loader) {
            [$loaderWarmed, $loaderFailed] = $this->warmComponentLoader(
                $loader,
                $loader->getComponentsDir(),
                $outputStream
            );
            $warmed += $loaderWarmed;
            $failed += $loaderFailed;
        }

        fwrite($outputStream, "  ✓ Views cache warmed ({$warmed} components parsed & compiled)\n");

        // A pass that could not build a component has not warmed it. Reporting success
        // here is how a broken graph reaches production with a green deploy.
        return $failed === 0;
    }

    /**
     * Parse and compile every component one loader owns.
     *
     * @param PhueComponentLoader $loader Loader whose directory is walked.
     * @param string $componentsDir Directory scanned for .phue files.
     * @param resource $outputStream Where progress and failures are reported.
     * @return array{0: int, 1: int} Components warmed, and components that failed.
     */
    private function warmComponentLoader(
        PhueComponentLoader $loader,
        string $componentsDir,
        $outputStream
    ): array {
        $warmed = 0;
        $failed = 0;
        $ssrSkipped = false;

        $phueFiles = [];
        if (is_dir($componentsDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($componentsDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'phue') {
                    $name = basename($file->getPathname(), '.phue');
                    $phueFiles[] = $name;
                }
            }
        }

        foreach ($phueFiles as $name) {
            try {
                if (!$loader->hasComponent($name)) {
                    continue;
                }

                $component = $loader->load($name);

                if ($component === null) {
                    fwrite($outputStream, "  ✗ Failed to warm component '{$name}': the structure could not be evaluated\n");
                    $failed++;

                    continue;
                }

                // buildFor() is fail-loud by contract: a child it cannot resolve aborts the
                // build and returns null rather than emitting a module that is quietly
                // missing a dependency. Counting that as warmed is how the pass reported a
                // component as ready while producing nothing for it.
                $module = $loader->buildFor($name);

                if ($module === null) {
                    fwrite($outputStream, "  ✗ Failed to warm component '{$name}': the client module could not be built\n");
                    $failed++;

                    continue;
                }

                // Try compiling SSR module; if sidecar is offline, suppress repeated spam lines
                if (!$ssrSkipped) {
                    try {
                        $loader->buildForSsr($name);
                    } catch (\Throwable) {
                        $ssrSkipped = true;
                    }
                }

                $warmed++;
            } catch (\Throwable $e) {
                fwrite($outputStream, "  ✗ Failed to warm component '{$name}': " . $e->getMessage() . "\n");
                $failed++;
            }
        }

        if ($ssrSkipped) {
            fwrite($outputStream, "  ⚠ SSR module compilation skipped (sidecar socket offline)\n");
        }

        return [$warmed, $failed];
    }

    private function optimizeSchemas(string $schemasDir, $outputStream): bool
    {
        fwrite($outputStream, "  Clearing schemas cache...\n");
        $files = glob($schemasDir . '/*');
        $count = 0;
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file) && unlink($file)) {
                    $count++;
                }
            }
        }
        fwrite($outputStream, "  ✓ Schemas cache cleared ({$count} files)\n");

        fwrite($outputStream, "  Warming schemas cache (generating for ActiveRecord models)...\n");

        try {
            $basePath = PathResolver::resolveBasePath();
            $config = new \Phue\Core\Config($basePath . '/config');
            $entityNamespace = (string) $config->get('schii.entityNamespace', 'App\\Models');
            $modelsDir = (string) $config->get('schii.modelsDir', $basePath . '/App/Models');
            $registry = new \Phue\Modules\Schii\Schema\SchemaRegistry(defaultModelNamespace: $entityNamespace);
            $warmed = 0;
            
            if (is_dir($modelsDir)) {
                $files = glob($modelsDir . '/*.php') ?: [];
                foreach ($files as $file) {
                    $className = rtrim($entityNamespace, '\\') . '\\' . basename($file, '.php');
                    if (class_exists($className) && is_subclass_of($className, \Phue\Core\Database\ActiveRecord\BaseActiveRecord::class)) {
                        try {
                            $registry->get($className);
                            $warmed++;
                        } catch (\Throwable $e) {
                            fwrite($outputStream, "  ✗ Failed to generate schema for {$className}: " . $e->getMessage() . "\n");
                        }
                    }
                }
            }

            fwrite($outputStream, "  ✓ Schemas cache warmed ({$warmed} models)\n");
            return true;

        } catch (\Throwable $e) {
            fwrite($outputStream, "  ⚠ Schema warming skipped (Schii module not available)\n");
            return true;
        }
    }

    private function optimizeSsr(string $basePath, $outputStream): bool
    {
        fwrite($outputStream, "  Clearing SSR sidecar cache debris...\n");
        $ssrCacheDir = $basePath . '/ssr/.cache';
        $files = glob($ssrCacheDir . '/*');
        $count = 0;
        $success = true;

        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    if (unlink($file)) {
                        $count++;
                    } else {
                        $success = false;
                    }
                }
            }
        }

        fwrite($outputStream, $success ? "  ✓ SSR cache cleared ({$count} files)\n" : "  ✗ SSR cache clear failed\n");

        fwrite($outputStream, "  Verifying SSR sidecar health...\n");

        $ssrConfigFile = $basePath . '/config/ssr.php';
        $ssrConfig = is_file($ssrConfigFile) ? require $ssrConfigFile : [];

        $socket = (string) ($ssrConfig['socket'] ?? env('SSR_SOCKET', '/tmp/phue-ssr.sock'));
        $connectTimeoutMs = (int) ($ssrConfig['connect_timeout_ms'] ?? env('SSR_CONNECT_TIMEOUT_MS', 200));
        $timeoutMs = (int) ($ssrConfig['timeout_ms'] ?? env('SSR_TIMEOUT_MS', 2000));
        $hostHeader = (string) ($ssrConfig['host_header'] ?? env('SSR_HOST_HEADER', 'phue-ssr'));

        $client = new UnixSsrClient(
            socket: $socket,
            connectTimeoutMs: $connectTimeoutMs,
            timeoutMs: $timeoutMs,
            hostHeader: $hostHeader
        );

        if ($client->isAvailable()) {
            fwrite($outputStream, "  ✓ SSR sidecar service is healthy\n");
        } else {
            fwrite($outputStream, "  ⚠ SSR sidecar service is offline or unreachable\n");
        }

        return $success;
    }
}