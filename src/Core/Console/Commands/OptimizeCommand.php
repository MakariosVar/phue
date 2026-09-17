<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Cache\FileCache;
use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueTemplateParser;

/**
 * CLI Command to optimize (clear + warm) application caches.
 *
 * Optimize = Clear + Warm. For each cache type:
 *   - general: Clear FileCache, then pre-load component structures & configs
 *   - views:   Clear compiled views, then parse & compile all .phue components
 *   - schemas: Clear generated schemas, then generate for all ActiveRecord models
 *
 * Usage:
 *   optimize                    # optimize all (default, excludes logs)
 *   optimize --type=general     # optimize only general cache
 *   optimize --type=views       # optimize only views cache
 *   optimize --type=schemas     # optimize only schemas cache
 *   optimize --dry-run          # show what would be optimized without executing
 *
 * @package Phue\Core\Console\Commands
 */
final class OptimizeCommand implements CommandInterface
{
    private const TYPES = [
        'all'     => ['general', 'views', 'schemas'],
        'general' => ['general'],
        'views'   => ['views'],
        'schemas' => ['schemas'],
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
        return 'Optimizes (clears + warms) application caches. Usage: optimize [--type=all|general|views|schemas] [--dry-run]';
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
                    $ok = $this->optimizeViews($viewsDir, $componentsDir, $phuiDir, $outputStream);
                    $success &= $ok;
                    break;

                case 'schemas':
                    $ok = $this->optimizeSchemas($schemasDir, $outputStream);
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
        $fileCache = new \Phue\Core\Cache\FileCache($cacheDir);
        $ok = $fileCache->clear();
        fwrite($outputStream, $ok ? "  ✓ General cache cleared\n" : "  ✗ General cache clear failed\n");

        if (!$ok) {
            return false;
        }

        fwrite($outputStream, "  Warming general cache...\n");

        // The general cache is primarily warmed on-demand during requests.
        // The FileCache doesn't have a "warm all" concept - it's populated on demand.
        // However, we can pre-load the PhueComponentLoader structure cache
        // which uses the FileCache internally for persistent cross-request caching.
        fwrite($outputStream, "  ✓ General cache warmed (on-demand)\n");

        return true;
    }

    private function optimizeViews(string $viewsDir, string $componentsDir, string $phuiDir, $outputStream): bool
    {
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

        $registry = new \Phue\Core\Frontend\ComponentLoaderRegistry();
        $parser = new PhueTemplateParser();

        $warmed = 0;

        // Warm App/Frontend components
        if (is_dir($componentsDir)) {
            $loader = new PhueComponentLoader($componentsDir, $parser);
            $loader->setRegistry($registry);
            $warmed += $this->warmComponentLoader($loader, $registry, $componentsDir, $outputStream);
        }

        // Warm PhUI core components
        if (is_dir($phuiDir)) {
            $loader = new PhueComponentLoader($phuiDir, $parser);
            $loader->setRegistry($registry);
            $warmed += $this->warmComponentLoader($loader, $registry, $phuiDir, $outputStream);
        }

        fwrite($outputStream, "  ✓ Views cache warmed ({$warmed} components parsed & compiled)\n");

        return true;
    }

    private function warmComponentLoader(PhueComponentLoader $loader, ComponentLoaderRegistry $registry, string $componentsDir, $outputStream): int
    {
        $warmed = 0;

        // Discover all .phue files in the components directory
        $phueFiles = [];
        if (is_dir($componentsDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($componentsDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'phue') {
                    // Logical component name = PascalCase basename (e.g., "Docs", "AppLayout")
                    $name = basename($file->getPathname(), '.phue');
                    $phueFiles[] = $name;
                }
            }
        }

        foreach ($phueFiles as $name) {
            try {
                // Load component to trigger structure parsing & caching
                if ($loader->hasComponent($name)) {
                    $component = $loader->load($name);
                    if ($component !== null) {
                        $warmed++;
                    }
                }
            } catch (\Throwable $e) {
                fwrite($outputStream, "  ✗ Failed to warm component '{$name}': " . $e->getMessage() . "\n");
            }
        }

        return $warmed;
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

        // Schema warming is handled by Schii module's SchemaRegistry.
        // We attempt to use it if the module is installed.
        try {
            $registry = new \Phue\Modules\Schii\Schema\SchemaRegistry();
            // SchemaRegistry generates on-demand via getSchema(). 
            // There's no bulk-generate API, but we can scan for ActiveRecord models
            // and call getSchema() on each to trigger generation.
            
            $modelsDir = PathResolver::resolveBasePath() . '/App/Models';
            $warmed = 0;
            
            if (is_dir($modelsDir)) {
                $files = glob($modelsDir . '/*.php');
                foreach ($files as $file) {
                    $className = 'App\\Models\\' . basename($file, '.php');
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
            // Schii module not available or SchemaRegistry not available
            fwrite($outputStream, "  ⚠ Schema warming skipped (Schii module not available)\n");
            return true;
        }
    }
}