<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\ComponentDiscoverer;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\TemplateCompilerBridge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Every shipped component must build, and the showcase page must build with them.
 *
 * The loader is fail-loud on purpose (PhueComponentLoader::buildFor): a component
 * whose module cannot be built aborts its parent rather than shipping a page with
 * a hole in it. That is the right call, but it means one broken `.phue` takes the
 * whole page's JavaScript down with it — and the symptom is not an error, it is a
 * page that quietly stops being interactive: no clicks, no scroll spy, no Prism,
 * no hydration, and no message saying why.
 *
 * So the build is asserted for every component, and again for a page assembled in a
 * temporary directory, which is the only place a cross-directory dependency (a page
 * component → a core component) actually resolves.
 *
 * The page is a fixture rather than this repository's own showcase page on purpose. The
 * framework ships no application, so there is no showcase here to build: a test that
 * reached for one would be asserting the shape of an app that happens to sit next to
 * this checkout, and would go red — or worse, stay green — for reasons that have
 * nothing to do with the loader.
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhuiComponentBuildTest extends TestCase
{
    private ComponentLoaderRegistry $registry;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ComponentLoaderRegistry();
        foreach (['src/Core/UI/Phui'] as $dir) {
            $loader = new PhueComponentLoader(__DIR__ . '/../../..' . '/' . $dir, null, null, null, new TemplateCompilerBridge());
            $this->registry->addLoader($loader);
            $loader->setRegistry($this->registry);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->written as $path) {
            @unlink($path);
        }

        @rmdir($this->fixtureDir());
        @rmdir(dirname($this->fixtureDir()));
    }

    /** @return array<string, array{string}> */
    public static function coreComponentProvider(): array
    {
        $cases = [];
        foreach (glob(__DIR__ . '/../../../src/Core/UI/Phui/*.phue') ?: [] as $file) {
            $cases[basename($file, '.phue')] = [basename($file, '.phue')];
        }
        return $cases;
    }

    #[DataProvider('coreComponentProvider')]
    public function testComponentModuleBuilds(string $name): void
    {
        $this->assertNotNull(
            $this->moduleSource($name),
            sprintf('%s has no module — its template or script does not compile.', $name)
        );
    }

    public function testPageDependingOnShippedComponentsBuildsAcrossTheLoaderChain(): void
    {
        $page = 'Pages/Fixture';
        $file = $this->fixtureDir() . '/Fixture.phue';

        file_put_contents($file, "<php>\nreturn ['title' => 'Fixture'];\n</php>\n<template>\n"
            . "<div class=\"fixture\">\n"
            . "    <ph-button>Ship</ph-button>\n"
            . "    <ph-alert tone=\"info\">Shipped</ph-alert>\n"
            . "</div>\n"
            . "</template>\n"
            . "<script>\n"
            . "export default { name: 'FixturePage' };\n"
            . "</script>\n");
        $this->written[] = $file;

        // The loader is pointed at the directory ABOVE Pages/, because that is what makes
        // the page's name "Pages/Fixture" — the same shape the App/Frontend loader had,
        // which is the whole point: the cross-directory resolution is what is under test.
        $loader = new PhueComponentLoader(dirname($this->fixtureDir()), null, null, null, new TemplateCompilerBridge());
        $this->registry->addLoader($loader);
        $loader->setRegistry($this->registry);

        $this->assertTrue($this->registry->hasComponent($page), 'the fixture page is missing');

        $discoverer = new ComponentDiscoverer($this->registry);
        $source = (string) file_get_contents($file);
        $this->assertSame(1, preg_match('/<template>(.*?)\n<\/template>/s', $source, $match));

        $unresolvable = [];
        foreach ($discoverer->scanTemplate($match[1], $page) as $dependency) {
            if ($this->registry->moduleHash($dependency) === null) {
                $unresolvable[] = $dependency;
            }
        }

        $this->assertSame([], $unresolvable, sprintf(
            'The page references component(s) with no module: %s. Because the '
            . 'loader is fail-loud, that silently removes the whole page script — the page '
            . 'stops being interactive with nothing logged.',
            implode(', ', $unresolvable)
        ));

        $this->assertNotNull(
            $this->moduleSource($page),
            'the page module does not build'
        );
    }

    /** The compiled module for a component, across the loader chain. */
    private function moduleSource(string $name): ?string
    {
        $hash = $this->registry->moduleHash($name);
        return $hash === null ? null : $this->registry->moduleFor($name, $hash);
    }

    /** Temporary Pages/ directory holding the page fixture. */
    private function fixtureDir(): string
    {
        $dir = sys_get_temp_dir() . '/phue_page_fixture_' . substr(md5(static::class), 0, 12) . '/Pages';

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }
}
