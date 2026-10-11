<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Application;
use Phue\Core\Container;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\TemplateCompilerBridge;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use ReflectionProperty;

/**
 * Ownership contract for the `phui.loader` container binding.
 *
 * `Container::bind()` overwrites silently (`$this->factories[$id] = $factory`),
 * so a second registration of the same id is not an error — it is a *silent*
 * demotion of the first one. Core registers `phui.loader` in
 * `registerCoreBindings()`, then `loadModules()` runs afterwards, so any module
 * that also binds that id always wins, whatever it passes in its factory.
 *
 * These tests pin that contract from both ends:
 *   - behavioural: the resolved loader keeps the dependencies Core injects,
 *   - structural: the binding is declared in exactly one place.
 *
 * @package Phue\Tests\Core\UI
 */
final class PhuiLoaderBindingTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpBase = sys_get_temp_dir() . '/phue_phui_binding_' . uniqid();
        mkdir($this->tmpBase . '/config', 0777, true);

        // PhueComponentLoader's constructor refuses a non-existent directory, and the
        // binding is resolved lazily — so an app that never touches the loader never
        // notices the directory is missing. The tests below do touch it, so it must exist.
        mkdir($this->tmpBase . '/src/Core/UI/Phui', 0777, true);

        file_put_contents($this->tmpBase . '/config/app.php', <<<PHP
<?php
return [
    'debug' => true,
    'modules' => ['\\Phue\\Modules\\Auth\\AuthModule', '\\Phue\\Modules\\Schii\\SchiiModule'],
];
PHP);

        file_put_contents($this->tmpBase . '/config/database.php', <<<PHP
<?php
return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ],
    ],
];
PHP);

        file_put_contents($this->tmpBase . '/config/schii.php', <<<PHP
<?php
return [
    'apiPrefix' => '/api/schii',
    'entityNamespace' => 'Phue\\Tests\\Core\\UI\\Stubs',
    'requireAuth' => false,
];
PHP);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpBase);
    }

    /**
     * The regression itself.
     *
     * Every diagnostic in PhueComponentLoader goes through `$this->logger?->warning(...)`.
     * If a module re-binds the loader without a logger, those become silent no-ops and a
     * component that fails to resolve turns into a blank page with no log line explaining it.
     */
    public function testResolvedPhuiLoaderRetainsLoggerWhenSchiiIsInstalled(): void
    {
        $app = new Application($this->tmpBase);

        $loader = $app->getContainer()->get('phui.loader');

        $this->assertInstanceOf(PhueComponentLoader::class, $loader);
        $this->assertNotNull(
            self::readPrivate($loader, 'logger'),
            'phui.loader resolved with no logger: Schii re-bound the id and silently dropped '
            . 'the PSR-3 logger Core injected, disabling every warning in PhueComponentLoader.',
        );
    }

    /**
     * Core must register the binding on its own.
     *
     * Guards the opposite failure from the one above: if someone "tidies up" by moving the
     * binding into Schii, every application without Schii loses the framework component
     * library loader entirely — and the registry swallows the miss (its loader closures are
     * wrapped in try/catch), so the breakage surfaces as missing components, not an error.
     */
    public function testCoreRegistersPhuiLoaderWithoutAnyModuleInstalled(): void
    {
        file_put_contents($this->tmpBase . '/config/app.php', <<<PHP
<?php
return [
    'debug' => true,
    'modules' => [],
];
PHP);

        $app = new Application($this->tmpBase);

        $loader = $app->getContainer()->get('phui.loader');

        $this->assertInstanceOf(PhueComponentLoader::class, $loader);
        $this->assertNotNull(
            self::readPrivate($loader, 'logger'),
            'Core must own the phui.loader binding; the framework component library is not '
            . 'optional, so the binding cannot depend on a module being installed.',
        );
    }

    /**
     * The remaining Core-injected dependencies, asserted with Schii installed.
     *
     * Green today, but they are the same class of silent demotion: a re-bind that forgets a
     * dependency degrades behaviour quietly rather than failing. Pinning them makes the next
     * re-bind fail loudly here instead.
     */
    public function testResolvedPhuiLoaderRetainsCacheAndBridgeWhenSchiiIsInstalled(): void
    {
        $app = new Application($this->tmpBase);

        $loader = $app->getContainer()->get('phui.loader');

        $this->assertNotNull(
            self::readPrivate($loader, 'persistentCache'),
            'phui.loader lost its persistent cache — component structure lookups go uncached.',
        );
        $this->assertInstanceOf(
            TemplateCompilerBridge::class,
            self::readPrivate($loader, 'compilerBridge'),
            'phui.loader lost the shared template compiler bridge.',
        );
    }

    /**
     * Structural guard: exactly one binding site for the id.
     *
     * Behavioural tests above prove the *effect* today. They cannot prove *why* it, and a
     * future module that binds the id with an identical factory would pass them while
     * re-introducing the trap for the next editor. This is the same source-scan technique
     * the Schii independence test uses.
     */
    public function testOnlyCoreBindsThePhuiLoaderId(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];

        foreach (glob($root . '/src/Modules/*/*.php') ?: [] as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }

            if (preg_match('/\b(?:singleton|bind)\s*\(\s*([\'"])phui\.loader\1/', $source) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Only Core may bind "phui.loader". Container::bind() overwrites without warning, '
            . 'so a module binding it later silently demotes Core\'s factory — that is how the '
            . 'logger was lost.',
        );
    }

    /**
     * `Container::bind()` overwriting silently is the root cause, so pin the behaviour that
     * makes it dangerous: rebinding an existing id does not warn, and does not preserve the
     * original factory.
     *
     * If this ever starts throwing or warning, the duplicate-binding hazard above is gone at
     * the source and this test is the reminder to update the contract tests accordingly.
     */
    public function testRebindingAnIdSilentlyReplacesTheFactory(): void
    {
        $container = new Container();
        $container->singleton('probe', static fn(): string => 'first');
        $this->assertSame('first', $container->get('probe'));

        $container->singleton('probe', static fn(): string => 'second');

        $this->assertSame(
            'second',
            $container->get('probe'),
            'bind() is expected to replace. The phui.loader contract tests depend on this '
            . 'being a silent replacement — that silence is the hazard they guard against.',
        );
    }

    /**
     * Reads a private loader property. The loader exposes no getter for its dependencies,
     * and adding public accessors purely for assertions would widen the API for no runtime
     * benefit.
     */
    private static function readPrivate(object $subject, string $property): mixed
    {
        $reflection = new ReflectionProperty($subject, $property);

        return $reflection->isInitialized($subject) ? $reflection->getValue($subject) : null;
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob("{$dir}/*") ?: [] as $entry) {
            is_dir($entry) ? $this->rmTree($entry) : unlink($entry);
        }

        rmdir($dir);
    }
}