<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Application;
use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;
use Phue\Core\Routing\Loader\AttributeRouteLoader;

/**
 * CLI Command to compile and cache application routes into a PHP array file.
 *
 * @package Phue\Core\Console\Commands
 */
final class RouteCacheCommand implements CommandInterface
{
    protected string $group = 'Dev';

    public function getGroup(): string
    {
        return $this->group;
    }

    public function getName(): string
    {
        return 'route:cache';
    }

    public function getDescription(): string
    {
        return 'Compiles and caches all controller routes into a PHP file for fast production loading.';
    }

    public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $basePath = PathResolver::resolveBasePath();
        $cacheFile = $basePath . '/storage/cache/routes.php';

        // 1. Bootstrap Application Kernel (initializes and registers all module & application routes into Router)
        $app = new Application($basePath);
        $router = $app->getRouter();
        $loader = new AttributeRouteLoader();

        // 2. Fetch all registered routes directly from the initialized Router.
        //    The kernel has already run every module's routes() and the loader
        //    has already scanned App/Controllers, so re-adding them here would
        //    register each route twice.
        $compiled = $router->getCompiledRoutes();

        // 3. Stamp the same source fingerprint the runtime will compute, so the
        //    command's output is accepted at boot instead of being discarded as
        //    stale.
        $fingerprint = $loader->fingerprint(
            $basePath,
            (array) $config->get('app.modules', [])
        );

        // 4. Export and write compiled routes to PHP array cache
        $loader->saveCache($cacheFile, $compiled, $fingerprint);

        $count = count($compiled);
        fwrite($outputStream, "  ✓ Routes cached successfully ({$count} routes compiled)\n");

        return 0;
    }
}