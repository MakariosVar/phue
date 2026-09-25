<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\TestCase;

/**
 * PhNavigationRemountE2ETest — the nav must survive being re-mounted.
 *
 * PhNavigation is not a passive component: its script self-boots. It mounts a
 * Vue app into `#nav` on DOMContentLoaded and mounts it AGAIN on every
 * `phue:navigate` event, which the SPA explorer dispatches on every client-side
 * navigation. So its re-mount path runs on every single navigation, and it is
 * the only Phui component that mounts a second app into a container it does not
 * own.
 *
 * This test drives the real compiled component in a real DOM (happy-dom + real
 * Vue) and asserts on the two things a source-reading test cannot see:
 *  1. `window.__phueNavApp` has to be the app, not what `app.mount()` returns.
 *     `mount()` returns the component's public instance proxy, which has no
 *     `unmount()`, so the "clean up the previous instance" guard silently never
 *     fires and the container keeps a registered app forever.
 *  2. The second mount must not produce Vue's mount/hydration warnings. It did:
 *     `el.innerHTML = ''` empties the container and `createSSRApp().mount()`
 *     always hydrates, so every SPA navigation logged
 *     "There is already an app instance mounted" plus
 *     "Attempting to hydrate existing markup but container is empty".
 *
 * Requirements (skipped, never failed, when absent):
 *  - `bun` on PATH, and vue + happy-dom in PhueE2EBaseUrl's deps dir.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class PhNavigationRemountE2ETest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';
    private const DEPS_DIR = '/tmp/phue-phcode-e2e-deps';
    private const BUN_TIMEOUT = 60;

    /** @var list<string> */
    private array $written = [];

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireRuntime();

        $this->workDir = sys_get_temp_dir() . '/phue-nav-e2e-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        symlink(self::DEPS_DIR . '/node_modules', $this->workDir . '/node_modules');
        $this->written[] = 'node_modules';

        $this->emit('PhNavigation');
        $this->emit('PhTheme');
        $this->extractNavSsr();
        file_put_contents($this->workDir . '/harness.mjs', self::harness());
    }

    private function extractNavSsr(): void
    {
        $full = file_get_contents('http://127.0.0.1:8000/phui');
        if ($full === false) {
            $this->fail('Cannot fetch /phui for the SSR nav fixture.');
        }

        if (!preg_match('/<div\b[^>]*\bid="nav"[^>]*>/i', $full, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('No #nav in the real /phui SSR HTML.');
        }

        $pos = $m[0][1] + strlen($m[0][0]);
        $depth = 0;
        $len = strlen($full);
        $start = $pos;

        while ($pos < $len) {
            $nextOpen = strpos($full, '<div', $pos);
            $nextClose = strpos($full, '</div>', $pos);
            if ($nextClose === false) {
                break;
            }

            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $pos = $nextOpen + 4;
            } else {
                if ($depth === 0) {
                    $inner = substr($full, $start, $nextClose - $start);
                    file_put_contents($this->workDir . '/nav-ssr.html', $inner);
                    $this->written[] = 'nav-ssr.html';

                    if (preg_match('/window\.__NAV_STATE__\s*=\s*(\{.*?\});/', $full, $mm)) {
                        file_put_contents($this->workDir . '/nav-state.json', $mm[1]);
                        $this->written[] = 'nav-state.json';
                    }

                    // The standalone nav app cannot resolve <ph-theme>
                    // (it is registered only inside the real page
                    // app). The real app SSR renders it as a <button>;
                    // the standalone client renders an unknown-element
                    // <ph-theme>. Normalise both to the same tag so
                    // hydration checks only the nav structure.
                    $inner = preg_replace('/<button[^>]*class="[^"]*nav-link[^"]*"[^>]*>.*?<\/button>/s', '<ph-theme></ph-theme>', $inner);
                    file_put_contents($this->workDir . '/nav-ssr.html', $inner);

                    return;
                }

                $depth--;
                $pos = $nextClose + 6;
            }
        }

        $this->fail('Could not locate the closing </div> for #nav.');
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            @unlink($this->workDir . '/' . $file);
        }

        if (isset($this->workDir) && is_dir($this->workDir)) {
            @rmdir($this->workDir);
        }

        parent::tearDown();
    }

    private function requireRuntime(): void
    {
        if (trim((string) shell_exec('command -v bun')) === '') {
            $this->markTestSkipped('bun is required to execute the PhNavigation client runtime.');
        }

        if (is_dir(self::DEPS_DIR . '/node_modules/vue') && is_dir(self::DEPS_DIR . '/node_modules/happy-dom')) {
            return;
        }

        @mkdir(self::DEPS_DIR, 0777, true);
        file_put_contents(self::DEPS_DIR . '/package.json', '{"name":"phue-phcode-e2e","private":true}');

        $output = [];
        $exit = -1;
        exec(sprintf('cd %s && bun add vue happy-dom 2>&1', escapeshellarg(self::DEPS_DIR)), $output, $exit);

        if ($exit !== 0 || !is_dir(self::DEPS_DIR . '/node_modules/vue')) {
            $this->markTestSkipped('Could not install vue/happy-dom for the E2E harness: ' . implode("\n", $output));
        }
    }

    /**
     * Compiles a Phui component and every child it references in its template.
     *
     * The loader inlines `<ph-theme>` as an absolute `/assets/phue/PhTheme.<hash>
     * .phue.js` import, which bun cannot resolve; those are rewritten to local
     * modules compiled from the same sources.
     */
    private function emit(string $component, array &$seen = []): void
    {
        $file = self::PHUI_DIR . '/' . $component . '.phue';

        if (!is_file($file)) {
            $this->fail("Component {$component}.phue is missing from the Phui directory.");
        }

        $parser = new PhueTemplateParser();
        $structure = $parser->parseStructure($file);
        $module = PhueComponentLoader::buildModule($structure['template'], $structure['script']);

        $module = preg_replace_callback(
            '~/assets/phue/([A-Za-z0-9_]+)\.[a-f0-9]{8}\.phue\.js~',
            function (array $matches) use (&$seen, $component): string {
                $child = $matches[1];

                if (!isset($seen[$child])) {
                    $seen[$child] = true;
                    $this->emit($child, $seen);
                }

                return './' . $child . '.mjs';
            },
            $module,
        );

        file_put_contents($this->workDir . '/' . $component . '.mjs', $module);
        $this->written[] = $component . '.mjs';
    }

    /**
     * @return array<string, mixed>
     */
    private function runCase(string $case): array
    {
        $output = [];
        $exit = -1;
        exec(
            sprintf(
                'cd %s && timeout %d bun harness.mjs %s 2>&1',
                escapeshellarg($this->workDir),
                self::BUN_TIMEOUT,
                escapeshellarg($case),
            ),
            $output,
            $exit,
        );

        $raw = implode("\n", $output);
        $this->assertSame(0, $exit, "harness case [{$case}] failed or timed out:\n{$raw}");

        for ($i = count($output) - 1; $i >= 0; $i--) {
            $decoded = json_decode(trim($output[$i]), true);
            if (is_array($decoded)) {
                // The harness filters the "Failed to resolve component" noise
                // (ph-theme resolves only inside the real page app, not in this
                // standalone nav app — same behaviour the real app has because
                // PhNavigation lives as a child of the page app).
                $decoded['warnings'] = array_values(array_filter(
                    $decoded['warnings'],
                    static fn(string $w): bool => !str_contains($w, 'Failed to resolve component'),
                ));

                return $decoded;
            }
        }

        $this->fail("harness case [{$case}] did not return JSON:\n{$raw}");
    }

    // ----------------------------------------------------------------- cases

    public function testFirstMountHydratesTheServerRenderedNavWithoutWarnings(): void
    {
        $result = $this->runCase('first');

        $this->assertSame(
            [],
            $result['warnings'],
            'The nav is server rendered, so the first mount hydrates real markup. '
            . 'Emptying #nav before createSSRApp().mount() is what produces "Attempting to '
            . 'hydrate existing markup but container is empty" on every single mount.',
        );
        $this->assertTrue($result['registeredApp'], 'The nav app must be registered on #nav.');
        $this->assertStringContainsString('Home', $result['rendered'], 'The nav must render its items.');
    }

    public function testReMountKeepsTheHandleUsableSoTheGuardCanActuallyRun(): void
    {
        $result = $this->runCase('remount');

        $this->assertIsString(
            $result['handleUnmount'],
            'window.__phueNavApp must expose unmount(). app.mount() returns the component '
            . 'public instance proxy, so assigning it to the handle makes the "unmount the '
            . 'previous instance" guard dead code: the previous app is never unmounted and '
            . '#nav keeps a registered app instance forever.',
        );
        $this->assertTrue(
            $result['handleIsContainerApp'],
            'The stored handle must be the very app registered on #nav, otherwise the '
            . 're-mount path is unmounting an unrelated instance.',
        );
    }

    public function testSpaReMountIsWarningFreeAndAppliesTheNewState(): void
    {
        $result = $this->runCase('remount');

        $this->assertSame(
            [],
            $result['warnings'],
            'Every client-side navigation dispatches phue:navigate, so this code path runs on '
            . 'every navigation. "There is already an app instance mounted" plus "container is '
            . 'empty" here means the nav leaks a Vue app per navigation.',
        );
        $this->assertStringContainsString(
            'Schii',
            $result['rendered'],
            'The re-mount must render the navState carried by the event, not the stale SSR markup.',
        );
        $this->assertStringNotContainsString(
            'Legacy',
            $result['rendered'],
            'Stale items from the previous render must be gone.',
        );
    }

    // ---------------------------------------------------------------- harness

    private static function harness(): string
    {
        return <<<'JS'
import { Window } from 'happy-dom';

const mode = process.argv[2] || 'first';
const warnings = [];

const win = new Window({
    url: 'http://127.0.0.1:8000/phui',
    settings: { disableJavaScriptFileLoading: true, disableCSSFileLoading: true },
});

const { document } = win;

// Vue's dev build logs through console.warn; capture instead of printing so the
// harness output stays parseable.
const realWarn = console.warn;
console.warn = (...args) => { warnings.push(args.join(' ').split('\n')[0]); };
console.error = (...args) => { warnings.push(args.join(' ').split('\n')[0]); };

document.body.innerHTML = '<div id="nav"></div>';
const ssr = await Bun.file('./nav-ssr.html').text();
document.getElementById('nav').innerHTML = ssr;
const navStateRaw = await Bun.file('./nav-state.json').text();
win.__NAV_STATE__ = JSON.parse(navStateRaw);

for (const key of [
    'document', 'navigator', 'location', 'getComputedStyle', 'Node', 'Element', 'HTMLElement',
    'SVGElement', 'Text', 'Comment', 'DocumentFragment', 'Event', 'CustomEvent',
    'MutationObserver', 'requestAnimationFrame', 'CSSStyleDeclaration',
]) {
    if (win[key] !== undefined) globalThis[key] = win[key];
}
globalThis.window = win;
globalThis.self = win;

// The import is what runs PhNavigation's self-boot block.
await import('./PhNavigation.mjs');

// The import is what runs PhNavigation's self-boot block.
await import('./PhNavigation.mjs');

document.dispatchEvent(new win.Event('DOMContentLoaded'));
await new Promise((r) => setTimeout(r, 60));

const snapshot = async (label) => {
    const fixture = await Bun.file('./nav-ssr.html').text();
    const el = document.getElementById('nav');
    const handle = win.__phueNavApp;
    return {
        label,
        warnings: warnings.splice(0, warnings.length),
        fixture: fixture.slice(0, 260),
        rendered: el ? el.innerHTML.slice(0, 260) : null,
        hasChildren: el ? el.hasChildNodes() : false,
        registeredApp: !!(el && el.__vue_app__),
        handleUnmount: handle ? typeof handle.unmount : 'no-handle',
        handleIsContainerApp: !!(handle && el && el.__vue_app__ === handle),
    };
};

const report = { mode };

if (mode === 'first') {
    Object.assign(report, await snapshot('first-mount'));
} else {
    Object.assign(report, await snapshot('first-mount'));

    win.dispatchEvent(new win.CustomEvent('phue:navigate', {
        detail: { navState: { items: [{ label: 'Schii', href: '/schii' }], actions: [] } },
    }));
    await new Promise((r) => setTimeout(r, 60));

    Object.assign(report, await snapshot('spa-remount'));
}

console.warn = realWarn;
console.log(JSON.stringify(report));
JS;
    }
}
