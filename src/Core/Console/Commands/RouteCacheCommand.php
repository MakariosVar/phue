<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Application;
use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;
use Phue\Core\Routing\Loader\AttributeRouteLoader;
use Phue\Core\Routing\Router;

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
        $controllersDir = $basePath . '/App/Controllers';

        // 1. Bootstrap Application Kernel to load configuration and modules
        $app = new Application($basePath);
        $router = $app->getRouter();
        $loader = new AttributeRouteLoader();

        // 2. Scan attribute-based application controllers
        if (is_dir($controllersDir)) {
            $router->scanControllers($controllersDir, 'App\\Controllers');
        }

        // 3. Collect programmatic routes exported from modules
        $container = $app->getContainer();
        $config = $app->getConfig();
        $moduleClasses = (array) $config->get('app.modules', []);

        foreach ($moduleClasses as $moduleClass) {
            if ($container->has($moduleClass)) {
                /** @var \Phue\Core\Module $module */
                $module = $container->get($moduleClass);
                $moduleRoutes = $module->getRoutesForCaching();

                foreach ($moduleRoutes as $route) {
                    $router->addRoute(
                        $route['method'],
                        $route['path'],
                        $route['handler'],
                        middleware: $route['middleware'] ?? []
                    );
                }
            }
        }

        // 4. Export and write compiled routes to PHP array cache
        $compiled = $router->getCompiledRoutes();
        $loader->saveCache($cacheFile, $compiled);

        $count = count($compiled);
        fwrite($outputStream, "  ✓ Routes cached successfully ({$count} routes compiled)\n");

        return 0;
    }
}