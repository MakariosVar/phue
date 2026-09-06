<?php

declare(strict_types=1);

namespace Phue\Tests\Core;

use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Core\EventDispatcher;
use Phue\Core\Module;
use Phue\Core\Routing\Router;
use Phue\Schii\Config\SchiiConfig;
use Phue\Schii\SchiiModule;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the Module abstraction.
 *
 * Before this fix every module was an ad-hoc class with a static register()
 * method. Now Application::loadModules() instantiates modules via the
 * container, injects router/container/events through setDependencies(), and
 * calls the instance init() method defined by Module.
 *
 * @package Phue\Tests\Core
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class ModuleTest extends TestCase
{
    public function testModuleIsAbstractWithInitMethod(): void
    {
        $ref = new \ReflectionClass(Module::class);

        $this->assertTrue($ref->isAbstract(), 'Module must be abstract');

        $init = $ref->getMethod('init');
        $this->assertTrue($init->isAbstract(), 'init() must be abstract');
        $this->assertSame('void', $init->getReturnType()->getName());
    }

    public function testSetDependenciesInjectsInfrastructure(): void
    {
        $module = new class extends Module {
            public function init(): void {}
        };

        $router     = new Router();
        $container  = new Container();
        $dispatcher = new EventDispatcher();

        $module->setDependencies($router, $container, $dispatcher);

        // getController uses the injected container — proves dependencies landed.
        $container->singleton(ServiceA::class, fn () => new ServiceA());
        $module->controllerMap['svc'] = ServiceA::class;

        $this->assertInstanceOf(ServiceA::class, $module->getController('svc'));
    }

    public function testGetControllerResolvesFromControllerNamespace(): void
    {
        $module = new class extends Module {
            public function init(): void {}
        };

        $container = new Container();
        $module->setDependencies(new Router(), $container, new EventDispatcher());
        $module->controllerNamespace = __NAMESPACE__;

        $instance = $module->getController('ModuleTest');

        $this->assertInstanceOf(ModuleTestController::class, $instance);
    }

    public function testGetControllerReturnsNullWhenNotFound(): void
    {
        $module = new class extends Module {
            public function init(): void {}
        };

        $module->setDependencies(new Router(), new Container(), new EventDispatcher());

        $this->assertNull($module->getController('NoSuchEntity'));
    }

    public function testGetModuleResolvesFromContainer(): void
    {
        $module = new class extends Module {
            public function init(): void {}
        };

        $container = new Container();
        $child     = new class extends Module { public function init(): void {} };
        $container->singleton('module.child', fn () => $child);

        $module->setDependencies(new Router(), $container, new EventDispatcher());

        $this->assertNull($module->getModule('missing'));
        $this->assertInstanceOf(Module::class, $module->getModule('child'));
    }

    public function testSchiiModuleInitRegistersSchiiConfig(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phue_module_init_' . uniqid();
        mkdir($tmpDir . '/config', 0777, true);
        file_put_contents($tmpDir . '/config/app.php', "<?php\nreturn ['debug' => true, 'modules' => ['\\Phue\\Schii\\SchiiModule']];\n");
        file_put_contents($tmpDir . '/config/schii.php', "<?php\nreturn ['apiPrefix' => '/api/schii', 'entityNamespace' => 'App\\Models'];\n");

        $container = new Container();
        $container->singleton(Config::class, fn () => new Config($tmpDir . '/config'));

        $module = new SchiiModule();
        $module->setDependencies(new Router(), $container, new EventDispatcher());
        $module->id = 'schii';
        $module->basePath = $tmpDir;

        $module->init();

        // SchiiModule::init() registers SchiiConfig in the container.
        $this->assertTrue($container->has(SchiiConfig::class), 'SchiiConfig must be registered after init()');
        $this->assertInstanceOf(SchiiConfig::class, $container->get(SchiiConfig::class));
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rmTree($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}

class ServiceA {}

class ModuleTestController {}