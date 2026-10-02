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
 * So the build is asserted for every component, and again for the showcase page
 * with the full loader chain, which is the only place a cross-directory
 * dependency (App component → core component) actually resolves.
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhuiComponentBuildTest extends TestCase
{
    private ComponentLoaderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ComponentLoaderRegistry();
        foreach (['src/Core/UI/Phui', 'App/Frontend'] as $dir) {
            $loader = new PhueComponentLoader(__DIR__ . '/../../..' . '/' . $dir, null, null, null, new TemplateCompilerBridge());
            $this->registry->addLoader($loader);
            $loader->setRegistry($this->registry);
        }
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

    public function testShowcasePageBuildsWithEveryDependency(): void
    {
        $page = 'Pages/PhUI';
        $this->assertTrue($this->registry->hasComponent($page), 'the showcase page is missing');

        $discoverer = new ComponentDiscoverer($this->registry);
        $source = (string) file_get_contents(
            __DIR__ . '/../../../App/Frontend/Pages/PhUI.phue'
        );
        $this->assertSame(1, preg_match('/<template>(.*?)\n<\/template>/s', $source, $match));

        $unresolvable = [];
        foreach ($discoverer->scanTemplate($match[1], $page) as $dependency) {
            if ($this->registry->moduleHash($dependency) === null) {
                $unresolvable[] = $dependency;
            }
        }

        $this->assertSame([], $unresolvable, sprintf(
            'The showcase page references component(s) with no module: %s. Because the '
            . 'loader is fail-loud, that silently removes the whole page script — the page '
            . 'stops being interactive with nothing logged.',
            implode(', ', $unresolvable)
        ));

        $this->assertNotNull(
            $this->moduleSource($page),
            'the showcase page module does not build'
        );
    }

    /** The compiled module for a component, across the loader chain. */
    private function moduleSource(string $name): ?string
    {
        $hash = $this->registry->moduleHash($name);
        return $hash === null ? null : $this->registry->moduleFor($name, $hash);
    }
}
