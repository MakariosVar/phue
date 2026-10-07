<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Core\EventDispatcher;
use Phue\Core\Routing\Router;
use Phue\Modules\Queue\Driver\DatabaseQueueDriver;
use Phue\Modules\Queue\Driver\SyncQueueDriver;
use Phue\Modules\Queue\PayloadCodec;
use Phue\Modules\Queue\Queue;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\QueueDriverInterface;
use Phue\Modules\Queue\QueueManager;
use Phue\Modules\Queue\QueueModule;

/**
 * What the module promises the rest of the application.
 *
 * Wiring is half of what a module does, and a test that assembled its own manager would pass
 * against wiring nobody used. Every assertion here goes through the container the module built for
 * itself, because the failure that matters — a binding that resolves to the wrong thing, or not at
 * all — is invisible from the outside until an application is already running.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class QueueModuleTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    private Container $container;

    private QueueModule $module;

    protected function tearDown(): void
    {
        parent::tearDown();

        Queue::swap(null);
        Container::setInstance(null);

        foreach ($this->tempDirs as $dir) {
            foreach ((array) glob($dir . '/*.php') as $file) {
                @unlink((string) $file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];
    }

    // ------------------------------------------------------------------ mounting

    public function testTheModuleDeclaresItselfALayerWithNoDependencies(): void
    {
        $this->boot();

        $this->assertSame('queue', $this->module->id);
        $this->assertSame('layer', $this->module->type);
        $this->assertSame([], $this->module->requires);
    }

    /**
     * The queue needs a store, not a framework module. Declaring a dependency on a database
     * module that does not exist would make mounting throw on every application that does not
     * use one.
     */
    public function testTheModuleNeverRequiresAnotherModule(): void
    {
        $this->boot();

        $this->assertFalse($this->module->requiresDb());
    }

    public function testTheModulePublishesItsConfiguration(): void
    {
        $this->boot(['driver' => 'sync', 'max_tries' => 7]);

        $this->assertInstanceOf(QueueConfig::class, $this->container->get(QueueConfig::class));
        $this->assertSame(7, $this->container->get(QueueConfig::class)->maxTries);
    }

    public function testTheDefaultDriverIsTheOneThatRunsJobsInline(): void
    {
        $this->boot();

        $driver = $this->container->get(QueueDriverInterface::class);

        $this->assertInstanceOf(SyncQueueDriver::class, $driver);
        $this->assertSame('sync', $driver->name());
    }

    public function testTheDatabaseDriverCanBeSelectedByName(): void
    {
        $this->boot(['driver' => 'database'], [\Phue\Tests\Support\SqliteFactory::memory()]);

        $this->assertSame('database', $this->container->get(QueueDriverInterface::class)->name());
    }

    /**
     * The connection is autowired rather than constructed here, so adding a driver with
     * dependencies of its own does not mean editing the module.
     */
    public function testTheDatabaseDriverGetsItsConnectionFromTheContainer(): void
    {
        $connection = \Phue\Tests\Support\SqliteFactory::memory();
        $this->boot(['driver' => 'database'], [$connection]);


        $driver = $this->container->get(QueueDriverInterface::class);

        $this->assertInstanceOf(DatabaseQueueDriver::class, $driver);
        $this->assertSame('queue_jobs', $driver->schema()->jobsTable());
    }

    /**
     * A connection is only opened when the configured driver needs one. Answering true for an
     * inline queue would make every boot pay for something it will never use.
     */
    public function testNoConnectionIsRequiredForTheInlineDriver(): void
    {
        $this->boot();

        $this->assertFalse($this->module->requiresDb());
    }

    public function testAConnectionIsRequiredOnceTheDatabaseDriverIsSelected(): void
    {
        $this->boot(['driver' => 'database'], [\Phue\Tests\Support\SqliteFactory::memory()]);

        $this->assertTrue($this->module->requiresDb());
    }

    /**
     * A boot that quietly succeeds and then fails on the first push sends people looking through
     * their own code. Said at mount time, it is one line.
     */
    public function testSelectingTheDatabaseDriverWithoutAConnectionStopsTheBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no database connection is configured/');

        $this->boot(['driver' => 'database']);
    }

    public function testThatRefusalSaysHowToFixIt(): void
    {
        try {
            $this->boot(['driver' => 'database']);
            $this->fail('Expected the boot to be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('config/database.php', $e->getMessage());
            $this->assertStringContainsString('QUEUE_DRIVER=sync', $e->getMessage());
        }
    }

    public function testTheInstallCommandIsRegistered(): void
    {
        $this->boot();

        $console = new \Phue\Core\Console\Application();
        $this->module->registerCommands($console);

        $help = stream_get_contents($this->renderHelp($console));

        $this->assertStringContainsString('queue:install', $help);
    }

    public function testTheManagerIsPublishedAgainstTheConfiguredDriver(): void
    {
        $this->boot();

        $manager = $this->container->get(QueueManager::class);

        $this->assertSame(
            $this->container->get(QueueDriverInterface::class),
            $manager->driver()
        );
    }

    public function testTheManagerIsSharedRatherThanRebuiltPerUse(): void
    {
        $this->boot();

        $this->assertSame(
            $this->container->get(QueueManager::class),
            $this->container->get(QueueManager::class)
        );
    }

    /**
     * The codec holds a reflection cache, so a manager that built its own on every push would
     * throw that cache away each time and pay for reflection on the request path.
     */
    public function testTheManagerUsesTheSharedCodec(): void
    {
        $this->boot();

        $this->assertSame(
            $this->container->get(PayloadCodec::class),
            $this->container->get(QueueManager::class)->codec()
        );
    }

    // ------------------------------------------------------------------ failing early

    public function testAnUnknownDriverNameIsRefusedWhileTheModuleBoots(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is "carrier-pigeon", which is not a known driver/');

        $this->boot(['driver' => 'carrier-pigeon']);
    }

    public function testTheErrorForAnUnknownDriverSaysHowToAddOne(): void
    {
        try {
            $this->boot(['driver' => 'carrier-pigeon']);
            $this->fail('Expected the driver to be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('queue.drivers.carrier-pigeon', $e->getMessage());
        }
    }

    public function testAClassThatIsNotADriverIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        $this->boot(['driver' => \stdClass::class]);
    }

    public function testAnIllegalConfigurationStopsTheModuleFromMounting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/queue\.table/');

        $this->boot(['table' => 'queue_jobs; DROP TABLE users']);
    }

    /**
     * A driver named by class works without the module knowing about it, which is what makes the
     * driver replaceable.
     */
    public function testADriverCanBeNamedByClass(): void
    {
        $this->boot(['driver' => SyncQueueDriver::class]);

        $this->assertSame('sync', $this->container->get(QueueDriverInterface::class)->name());
    }

    /**
     * The console's help text, which is what somebody actually reads to find a command.
     *
     * @return resource
     */
    private function renderHelp(\Phue\Core\Console\Application $console)
    {
        $out = fopen('php://memory', 'r+');
        $console->run(['phue', 'help'], $out, $out);

        rewind($out);

        return $out;
    }

    /**
     * @param array<string, mixed>   $config      The queue section to write.
     * @param list<\Phue\Core\Database\Connection> $connections Connections to offer the
     *        container, so a driver with dependencies can be built.
     */
    private function boot(array $config = [], array $connections = []): void
    {
        $dir = sys_get_temp_dir() . '/phue_queue_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        file_put_contents($dir . '/app.php', '<?php return ["debug" => false];');
        file_put_contents($dir . '/queue.php', '<?php return ' . var_export($config, true) . ';');

        $this->container = new Container();
        $this->container->singleton(Config::class, static fn(): Config => new Config($dir));

        foreach ($connections as $index => $connection) {
            $this->container->set(\Phue\Core\Database\Connection::class, static fn(): \Phue\Core\Database\Connection => $connection);
        }

        $this->module = new QueueModule();
        $this->module->setDependencies(new Router(), $this->container, new EventDispatcher());
        $this->module->init();

        Container::setInstance($this->container);
    }
}