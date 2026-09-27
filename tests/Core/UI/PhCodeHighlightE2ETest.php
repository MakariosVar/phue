<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * PhCodeHighlightE2ETest — end-to-end proof that PhCode actually highlights.
 *
 * Everything else in the PhCode suite reads source text. This one runs the real
 * compiled component (PhueComponentLoader::buildModule) inside a real DOM
 * (happy-dom) with real Vue 3, against the Prism files the running server
 * actually serves from /assets/vendor/prismjs, and asserts on the resulting
 * DOM: real `<span class="token">` markup, not "the string highlightElement
 * appears in the source".
 *
 * It exists because of a shipped-forever bug: PhCode pointed Prism at a public
 * CDN while the framework hardcodes `script-src 'self'; style-src 'self'`
 * (src/Core/Security/SecurityMiddleware.php). Every unit test passed, the
 * component compiled, and the block rendered as permanently unhighlighted text
 * in every browser. Only a real DOM against a real server catches that class
 * of failure.
 *
 * Requirements (skipped, never failed, when absent):
 *  - `bun` on PATH (the client runtime is executed with it),
 *  - a Phue server reachable on PhueE2EBaseUrl (defaults to 127.0.0.1:8000),
 *  - the vendor tree installed via `bin/phue npm:install prismjs`.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class PhCodeHighlightE2ETest extends TestCase
{
    private const PHCODE = __DIR__ . '/../../../src/Core/UI/Phui/PhCode.phue';
    private const PHDROPDOWN = __DIR__ . '/../../../src/Core/UI/Phui/PhDropdown.phue';
    private const PHICON = __DIR__ . '/../../../src/Core/UI/Phui/PhIcon.phue';
    private const VENDOR_ROOT = __DIR__ . '/../../../storage/assets/vendor/prismjs';
    private const BASE_URL = 'http://127.0.0.1:8000';
    private const DEPS_DIR = '/tmp/phue-phcode-e2e-deps';
    private const BUN_TIMEOUT = 60;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireRuntime();
        $this->requireVendorTree();
        $this->requireServer();

        $this->workDir = sys_get_temp_dir() . '/phue-phcode-e2e-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        symlink(self::DEPS_DIR . '/node_modules', $this->workDir . '/node_modules');

        // The real Phui components, compiled by the real loader: the theme picker
        // is a <ph-dropdown> and has to be exercised as one, stubs would prove
        // nothing about the markup the browser gets.
        $parser = new PhueTemplateParser();

        foreach ([
            'component' => self::PHCODE,
            'dropdown' => self::PHDROPDOWN,
            'icon' => self::PHICON,
        ] as $name => $file) {
            $structure = $parser->parseStructure($file);
            file_put_contents(
                $this->workDir . '/' . $name . '.mjs',
                TestComponentLoader::moduleFor($structure['template'], $structure['script']),
            );
        }

        file_put_contents($this->workDir . '/harness.mjs', self::harness());
    }

    protected function tearDown(): void
    {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            foreach (['component', 'dropdown', 'icon'] as $name) {
                @unlink($this->workDir . '/' . $name . '.mjs');
            }
            @unlink($this->workDir . '/harness.mjs');
            @unlink($this->workDir . '/node_modules');
            @rmdir($this->workDir);
        }

        parent::tearDown();
    }

    private function requireRuntime(): void
    {
        if (trim((string) shell_exec('command -v bun')) === '') {
            $this->markTestSkipped('bun is required to execute the PhCode client runtime.');
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

    private function requireVendorTree(): void
    {
        foreach (['components/prism-core.min.js', 'plugins/autoloader/prism-autoloader.min.js', 'themes/prism-tomorrow.min.css'] as $file) {
            if (!is_file(self::VENDOR_ROOT . '/' . $file)) {
                $this->markTestSkipped(
                    'Prism vendor tree is missing (' . $file . '). Run `bin/phue npm:install prismjs` first.',
                );
            }
        }
    }

    private function requireServer(): void
    {
        $output = [];
        $exit = -1;
        exec(sprintf('curl -s -o /dev/null -w "%%{http_code}" -m 10 %s/assets/vendor/prismjs/components/prism-core.min.js', escapeshellarg(self::BASE_URL)), $output, $exit);

        if ($exit !== 0 || trim((string) ($output[0] ?? '')) !== '200') {
            $this->markTestSkipped('No Phue server serving the vendor tree at ' . self::BASE_URL . '.');
        }
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
                return $decoded;
            }
        }

        $this->fail("harness case [{$case}] did not return JSON:\n{$raw}");
    }

    // ----------------------------------------------------------------- cases

    public function testVendorServedBlockIsTokenizedByPrism(): void
    {
        $result = $this->runCase('single');

        $this->assertSame([], $result['failures'], 'No asset request may fail.');
        $this->assertSame(
            [
                'head:' . self::BASE_URL . '/assets/vendor/prismjs/components/prism-core.min.js',
                'head:' . self::BASE_URL . '/assets/vendor/prismjs/plugins/autoloader/prism-autoloader.min.js',
            ],
            array_slice($result['scripts'], 0, 2),
            'Core and autoloader must be requested from the same-origin vendor root, in order.',
        );
        $this->assertCount(3, $result['scripts'], 'Exactly core, autoloader and one grammar: no more.');
        $this->assertContains(
            // Prism's autoloader injects grammar scripts into document.body.
            'body:' . self::BASE_URL . '/assets/vendor/prismjs/components/prism-markup.min.js',
            $result['scripts'],
            'The grammar must be requested from the same origin as well.',
        );

        $this->assertSame(
            [self::BASE_URL . '/assets/vendor/prismjs/themes/prism-twilight.min.css'],
            $result['links'],
            'Exactly one theme stylesheet, with a clean path (a doubled slash here means '
            . 'the base was concatenated with a trailing slash). Twilight is PhCode\'s '
            . 'declared default theme.',
        );

        $this->assertSame('1', $result['highlighted'], 'The block must report a successful highlight.');
        $this->assertTrue($result['grammarLoaded'], 'The requested grammar must be registered in Prism.languages.');
        $this->assertSame('/assets/vendor/prismjs/components/', $result['languagesPath']);
        $this->assertGreaterThan(
            5,
            $result['tokenCount'],
            'Prism must have produced real token markup. Zero tokens is exactly the '
            . '"loads but never highlights" failure this test exists for.',
        );
        $this->assertStringContainsString('<span class="token', $result['html']);
        $this->assertSame(
            '1',
            $result['preOpacity'],
            'The pending opacity must be released once the first pass settles. A block that stays '
            . 'dimmed is the failure mode of a "pending" flag that is only cleared on success.',
        );
        $this->assertStringContainsString('language-markup', $result['codeClass']);
    }

    public function testLineNumbersPluginEndsUpOnThePreElement(): void
    {
        $result = $this->runCase('lines');

        $this->assertContains(
            'head:' . self::BASE_URL . '/assets/vendor/prismjs/plugins/line-numbers/prism-line-numbers.min.js',
            $result['scripts'],
        );
        $this->assertContains(
            self::BASE_URL . '/assets/vendor/prismjs/plugins/line-numbers/prism-line-numbers.min.css',
            $result['links'],
        );

        $this->assertSame('1', $result['highlighted']);
        $this->assertStringContainsString(
            'line-numbers',
            $result['preClass'],
            'The plugin moves the class from <code> to <pre>; without it on <code> the '
            . 'plugin never runs and no line numbers are drawn.',
        );
        $this->assertStringNotContainsString(
            'line-numbers',
            $result['codeClass'],
            'The class is expected to be consumed by the plugin; a leftover copy on <code> '
            . 'means the plugin never executed.',
        );
    }

    public function testSeveralBlocksShareOnePrismCoreAndLoadTheirOwnGrammar(): void
    {
        $result = $this->runCase('two');

        $core = array_values(array_filter(
            $result['scripts'],
            static fn(string $s): bool => str_ends_with($s, '/components/prism-core.min.js'),
        ));
        $autoloader = array_values(array_filter(
            $result['scripts'],
            static fn(string $s): bool => str_ends_with($s, '/plugins/autoloader/prism-autoloader.min.js'),
        ));

        $this->assertCount(1, $core, 'Two blocks must share a single core request.');
        $this->assertCount(1, $autoloader);

        $this->assertSame('1', $result['blocks'][0]['highlighted']);
        $this->assertSame('1', $result['blocks'][1]['highlighted']);
        $this->assertGreaterThan(3, $result['blocks'][0]['tokenCount'], 'PHP block must be tokenized.');
        $this->assertGreaterThan(0, $result['blocks'][1]['tokenCount'], 'Bash block must be tokenized.');

        $this->assertContains('body:' . self::BASE_URL . '/assets/vendor/prismjs/components/prism-php.min.js', $result['scripts']);
        $this->assertContains('body:' . self::BASE_URL . '/assets/vendor/prismjs/components/prism-bash.min.js', $result['scripts']);
    }

    public function testThemePickerSwitchesTheStylesheetWithoutLosingTheHighlight(): void
    {
        $result = $this->runCase('picker');
        $picker = $result['picker'] ?? [];

        $this->assertArrayNotHasKey('error', $picker, 'The picker trigger must render: ' . json_encode($picker));
        $this->assertArrayNotHasKey('missing:Okaidia', $picker, 'Okaidia must be selectable.');
        $this->assertArrayNotHasKey('missing:Twilight', $picker, 'Twilight must be selectable.');

        $this->assertSame(
            ['Coy', 'Dark', 'Funky', 'Okaidia', 'Solarized Light', 'Tomorrow', 'Twilight'],
            $picker['opened']['labels'],
            'The menu must list every theme shipped by the vendor tree, with a readable label.',
        );
        $this->assertStringContainsString(
            'dropdown-menu',
            $picker['opened']['menuClass'] ?? '',
            'The picker must render a Bootstrap dropdown menu.',
        );
        $this->assertSame(
            ['Twilight'],
            $picker['opened']['checked'],
            'The default theme must be the one marked as active.',
        );

        foreach (['okaidia' => 'okaidia', 'twilight' => 'twilight', 'backToOkaidia' => 'okaidia'] as $step => $theme) {
            $this->assertSame(
                [self::BASE_URL . '/assets/vendor/prismjs/themes/prism-' . $theme . '.min.css'],
                $picker[$step]['themeLinks'],
                'After [' . $step . '] exactly one theme stylesheet must be in the head, and it must '
                . 'be the selected one.',
            );
            $this->assertSame('1', $picker[$step]['highlighted'], 'A theme switch must not un-highlight the block.');
            $this->assertGreaterThan(
                3,
                $picker[$step]['tokenCount'],
                'Re-highlighting after a theme switch must keep producing tokens.',
            );
        }

        $this->assertStringContainsString('Okaidia', $picker['backToOkaidia']['trigger'], 'The trigger must show the live selection.');
        $this->assertSame(['Okaidia'], $picker['backToOkaidia']['checked'], 'The check must follow the selection.');
        $this->assertStringContainsString('total', $picker['backToOkaidia']['text'], 'The code text must survive the re-render.');
    }

    // ---------------------------------------------------------------- harness

    /**
     * happy-dom + real Vue running the real compiled PhCode against the live server.
     */
    private static function harness(): string
    {
        return <<<'JS'
import { Window } from 'happy-dom';

const BASE = 'http://127.0.0.1:8000';
const mode = process.argv[2] || 'single';

const CASES = {
    single: [{ language: 'vue', lineNumbers: false, code: '<ph-input label="Name" :value="name" @update:model-value="name = $event" />' }],
    lines: [{ language: 'vue', lineNumbers: true, code: '<ph-input label="Name" :value="name" @update:model-value="name = $event" />' }],
    two: [
        { language: 'php', lineNumbers: false, code: '<?php\nfunction total(array $n): int { return array_sum($n); }' },
        { language: 'bash', lineNumbers: false, code: 'for f in *.phue; do echo "$f"; done' },
    ],
    picker: [{ language: 'php', lineNumbers: false, code: '<?php\nfunction total(array $n): int { return array_sum($n); }' }],
};

const network = { scripts: [], links: [], failures: [] };

const win = new Window({
    url: BASE + '/',
    // The harness performs the fetch/eval itself, so happy-dom's own
    // HTMLScriptElement loader must not race it and fire a bogus 'error'.
    settings: { disableJavaScriptFileLoading: true, disableCSSFileLoading: true },
});

const { document } = win;

// Prism removes its own grammar <script> from document.body in onload/onerror;
// the harness never really attaches scripts, so make removal a no-op instead of
// letting a NotFoundError break Prism's own success callback.
const keepScriptRemoval = (parent) => {
    const native = parent.removeChild.bind(parent);
    return (node) => (String(node.tagName).toLowerCase() === 'script' ? node : native(node));
};
document.body.removeChild = keepScriptRemoval(document.body);
document.head.removeChild = keepScriptRemoval(document.head);

const nativeAppendHead = document.head.appendChild.bind(document.head);
const nativeAppendBody = document.body.appendChild.bind(document.body);

const instrument = (nativeAppend, sink) => (node) => {
    const tag = String(node.tagName).toLowerCase();

    if (tag === 'script' && (node.getAttribute('src') || node.src)) {
        const url = new URL(node.getAttribute('src') || node.src, BASE + '/').href;
        network.scripts.push(sink + ':' + url);
        queueMicrotask(async () => {
            try {
                const res = await fetch(url);
                if (!res.ok) {
                    network.failures.push([url, res.status]);
                    node.dispatchEvent(new win.Event('error'));
                    return;
                }
                const source = await res.text();
                try {
                    Object.defineProperty(document, 'currentScript', { value: node, configurable: true, writable: true });
                } catch {}
                win.eval(source);
                node.dispatchEvent(new win.Event('load'));
            } catch (e) {
                network.failures.push([url, String(e)]);
                node.dispatchEvent(new win.Event('error'));
            }
        });
        return node;
    }

    if (tag === 'link' && (node.getAttribute('href') || node.href)) {
        network.links.push(new URL(node.getAttribute('href') || node.href, BASE + '/').href);
        // Really attached: PhCode's theme dedupe works by querying the DOM.
        return nativeAppend(node);
    }

    // Scripts stay detached (see above); everything else goes in for real,
    // otherwise Vue would mount into a detached subtree.
    return nativeAppend(node);
};

document.head.appendChild = instrument(nativeAppendHead, 'head');
document.body.appendChild = instrument(nativeAppendBody, 'body');

for (const key of [
    'document', 'navigator', 'location', 'getComputedStyle', 'Node', 'Element', 'HTMLElement',
    'SVGElement', 'Text', 'Comment', 'DocumentFragment', 'HTMLTemplateElement', 'Event',
    'CustomEvent', 'MutationObserver', 'requestAnimationFrame', 'CSSStyleDeclaration', 'Image',
]) {
    if (win[key] !== undefined) {
        globalThis[key] = win[key];
    }
}
globalThis.window = win;
globalThis.self = win;

// Imported AFTER the DOM globals exist: @vue/runtime-dom captures `document`
// at module scope, so a static import would see undefined.
const { createApp, h } = await import('vue');
const mod = await import('./component.mjs');
const dropdown = await import('./dropdown.mjs');
const icon = await import('./icon.mjs');

const apps = [];
const blocks = [];
for (const props of CASES[mode]) {
    const point = document.createElement('div');
    document.body.appendChild(point);
    const app = createApp({ render: () => h({ ...mod.default, render: mod.render }, props) });
    // Registered globally, exactly like the framework app does: the compiled
    // PhCode resolves them by tag name.
    app.component('ph-dropdown', { ...dropdown.default, render: dropdown.render });
    app.component('ph-icon', { ...icon.default, render: icon.render });
    app.mount(point);
    apps.push(app);
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

for (let i = 0; i < 80; i += 1) {
    await wait(25);
    const codes = [...document.querySelectorAll('code')];
    if (codes.length === CASES[mode].length && codes.every((el) => el.getAttribute('data-ph-highlighted') === '1')) {
        break;
    }
}
await wait(150);

for (const el of document.querySelectorAll('code')) {
    const pre = el.parentElement;
    blocks.push({
        highlighted: el.getAttribute('data-ph-highlighted'),
        codeClass: el.className,
        preClass: pre ? pre.className : null,
        preOpacity: pre ? pre.style.opacity : null,
        tokenCount: el.querySelectorAll('span.token').length,
        html: el.innerHTML.slice(0, 300),
        text: el.textContent,
    });
}

const prisms = win.Prism;
const report = {
    scripts: network.scripts,
    links: network.links,
    failures: network.failures,
    hasPrism: !!prisms,
    languagesPath: prisms && prisms.plugins && prisms.plugins.autoloader ? prisms.plugins.autoloader.languages_path : null,
    grammarLoaded: mode === 'lines' ? !!prisms.languages.markup : !!prisms.languages.markup,
    blocks,
};

// ------------------------------------------------------------- theme picker
if (mode === 'picker') {
    const click = (el) => el.dispatchEvent(new win.Event('click', { bubbles: true, cancelable: true }));
    const themeLinks = () => [...document.querySelectorAll('link[data-prism-css^="theme-"]')]
        .map((l) => new URL(l.getAttribute('href') || l.href, BASE + '/').href);
    const menuItems = () => [...document.querySelectorAll('.dropdown-menu .dropdown-item')];
    const code = () => document.querySelector('code');
    const snapshot = () => ({
        themeLinks: themeLinks(),
        trigger: (document.querySelector('.theme-picker') || {}).textContent?.trim() || null,
        checked: menuItems().filter((el) => el.querySelector('.bi-check-lg')).map((el) => el.textContent.trim()),
        highlighted: code() ? code().getAttribute('data-ph-highlighted') : null,
        tokenCount: code() ? code().querySelectorAll('span.token').length : -1,
        text: code() ? code().textContent : null,
    });

    const steps = { closed: snapshot() };

    const trigger = document.querySelector('.theme-picker');
    if (!trigger) {
        steps.error = 'no .theme-picker trigger rendered';
    } else {
        click(trigger);
        await wait(40);
        steps.opened = {
            ...snapshot(),
            labels: menuItems().map((el) => el.textContent.trim()),
            menuClass: (document.querySelector('.dropdown-menu') || {}).className || '',
        };

        const pick = async (label) => {
            if (!document.querySelector('.dropdown-menu')) {
                click(document.querySelector('.theme-picker'));
                await wait(40);
            }
            const item = menuItems().find((el) => el.textContent.trim() === label);
            if (!item) {
                steps['missing:' + label] = true;
                return;
            }
            click(item);
            await wait(350);
            // The dropdown closes on select; reopen it so the next assertion can
            // see which item carries the active check.
            click(document.querySelector('.theme-picker'));
            await wait(40);
        };

        await pick('Okaidia');
        steps.okaidia = snapshot();

        // A -> B -> A: the stale stylesheet used to stay in the head and win the
        // cascade, so the second pick of the same theme looked like a no-op.
        await pick('Twilight');
        steps.twilight = snapshot();
        await pick('Okaidia');
        steps.backToOkaidia = snapshot();
    }

    report.picker = steps;
}

if (mode !== 'two') {
    report.highlighted = blocks[0] ? blocks[0].highlighted : null;
    report.codeClass = blocks[0] ? blocks[0].codeClass : null;
    report.preClass = blocks[0] ? blocks[0].preClass : null;
    report.preOpacity = blocks[0] ? blocks[0].preOpacity : null;
    report.tokenCount = blocks[0] ? blocks[0].tokenCount : -1;
    report.html = blocks[0] ? blocks[0].html : null;
    report.text = blocks[0] ? blocks[0].text : null;
}

console.log(JSON.stringify(report));
for (const app of apps) {
    app.unmount();
}
JS;
    }
}
