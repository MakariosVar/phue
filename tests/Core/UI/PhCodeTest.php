<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueTemplateParser;
use Phue\Tests\Core\Stubs\AssertsPhueJsModuleContract;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * PhCodeTest — contract locks for the built-in PhCode component.
 *
 * PhCode is the framework's only syntax-highlighting primitive and it has no
 * build step: the `<script>` block is served verbatim as an ESM module and
 * pulls Prism.js at runtime. That means every regression in it ships silently
 * (a code block that renders as grey mush is indistinguishable from "no
 * theme") and is only ever caught by a human staring at a page. These tests
 * freeze the contract instead.
 *
 * The invariants locked here, and the failure each one guards:
 *
 *  - same-origin sourcing: the framework hardcodes `script-src 'self'` and
 *    `style-src 'self'` (src/Core/Security/SecurityMiddleware.php), so ANY
 *    absolute origin is blocked by the browser and the block silently degrades
 *    to plain text. Prism therefore has to come from the framework's own npm
 *    vendor pipeline (`bin/phue npm:install prismjs` -> /assets/vendor/prismjs).
 *  - the grammar path is pinned explicitly on the autoloader: it otherwise
 *    guesses it from `document.currentScript`, which turns every grammar
 *    request into a 404.
 *  - grammar is awaited BEFORE `highlightElement`: the autoloader only reacts
 *    to Prism's `complete` hook, i.e. after a highlighting pass. Without an
 *    explicit `loadLanguages()` the first pass renders raw text and the block
 *    is never re-highlighted.
 *  - `line-numbers` sits on the `<code>` element: the plugin gates on
 *    `Prism.util.isActive(codeElement, 'line-numbers')`, and then MOVES the
 *    class to the `<pre>`. Binding it only on the `<pre>` silently produces a
 *    block with no line numbers at all.
 *  - the code element is re-synced from the prop (`textContent = code`)
 *    before every highlight pass: Prism rewrites `innerHTML`, so a language
 *    switch would otherwise re-tokenize Prism's own markup.
 *  - missing/failed assets degrade to plain text instead of throwing, with a
 *    warning that names the exact command that fixes it.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhCodeTest extends TestCase
{
    private const PHCODE = __DIR__ . '/../../../src/Core/UI/Phui/PhCode.phue';

    private string $template;
    private string $script;
    private string $module;

    protected function setUp(): void
    {
        parent::setUp();

        $structure = (new PhueTemplateParser())->parseStructure(self::PHCODE);

        $this->template = $structure['template'] ?? '';
        $this->script = $structure['script'] ?? '';
        $this->module = TestComponentLoader::moduleFor($this->template, $this->script);

        $this->assertNotSame('', $this->template, 'PhCode must expose a <template> block.');
        $this->assertNotSame('', $this->script, 'PhCode must expose a <script> block.');
    }

    /**
     * Index of the first occurrence of $needle, or -1.
     */
    private function pos(string $needle): int
    {
        return strpos($this->script, $needle);
    }

    // ---------------------------------------------------------------- markup

    public function testTemplateKeepsServerRenderedCodeForNoJsAndSsr(): void
    {
        $this->assertMatchesRegularExpression(
            '/<code\b[^>]*\bv-text="code"/',
            $this->template,
            'PhCode must bind the code prop with v-text: the block has to stay readable '
            . 'with JavaScript disabled and in the SSR pass.',
        );
        $this->assertMatchesRegularExpression(
            '/<pre\b[^>]*>[\s\S]*<code\b/',
            $this->template,
            'Prism requires <code> to be the direct child of <pre> (it reads the parent '
            . 'node to decide on line numbers and on the pre>code theme selectors).',
        );
        $this->assertStringContainsString('ref="codeEl"', $this->template);
    }

    public function testLineNumbersClassIsBoundOnTheCodeElementToo(): void
    {
        $this->assertSame(
            2,
            preg_match_all('/\bline-numbers\b/', $this->template),
            'The line-numbers class must be bound on BOTH <pre> and <code>: the Prism '
            . 'plugin gates on isActive(<code>, "line-numbers") and then moves the class '
            . 'to the <pre>.',
        );
    }

    public function testTemplateExposesHighlightStateForDiagnostics(): void
    {
        $this->assertStringContainsString(
            'data-ph-highlighted',
            $this->template,
            'PhCode must expose whether Prism actually highlighted the block; a silent '
            . 'plain-text fallback is otherwise indistinguishable from a missing theme.',
        );
    }

    public function testCopyControlKeepsClipboardContract(): void
    {
        foreach (['@click="copy"', ':disabled="copying"', 'aria-label'] as $needle) {
            $this->assertStringContainsString($needle, $this->template, "PhCode template lost {$needle}.");
        }

        $this->assertStringContainsString('navigator.clipboard', $this->script);
        $this->assertStringContainsString('document.execCommand', $this->script);
        $this->assertStringContainsString(
            'execCommand copy returned false',
            $this->script,
            'The execCommand fallback must check its return value instead of assuming success.',
        );
        $this->assertStringContainsString(
            "this.copied = false;",
            $this->script,
            'A failed copy must not leave the button stuck in the copied state.',
        );
    }

    // ------------------------------------------------------------- sourcing

    public function testPrismIsServedFromTheSameOriginVendorRoot(): void
    {
        $this->assertSame(
            0,
            preg_match_all('#https?://#i', $this->script),
            'PhCode must not reference any absolute origin. The framework hardcodes '
            . "script-src 'self' / style-src 'self' (SecurityMiddleware), so a CDN URL is "
            . 'blocked in the browser and the block degrades to plain text forever.',
        );

        $this->assertStringContainsString(
            "const DEFAULT_ASSET_BASE = '/assets/vendor/prismjs'",
            $this->script,
            'Prism must come from the framework npm vendor root, which is served same-origin '
            . 'on every adapter (FPM, Swoole, RoadRunner).',
        );
        $this->assertStringNotContainsString('/assets/vendor/prism/', $this->script);
        $this->assertDoesNotMatchRegularExpression(
            '#[\'"]/(?:app|var|home|srv|usr|opt)/#',
            $this->script,
            'The client must only ever address assets through a web path. A container '
            . 'filesystem path in the loader is unreproducible outside the dev container.',
        );
    }

    public function testAssetRootIsOverridableAndNormalized(): void
    {
        $this->assertStringContainsString(
            'assetBase: { type: String, default: DEFAULT_ASSET_BASE }',
            $this->script,
            'The vendor root must be overridable per component, so an app can point at a '
            . 'versioned or bundled copy without forking the component.',
        );
        $this->assertStringContainsString(
            "return this.assetBase.replace(/\\/+$/, '');",
            $this->script,
            'A trailing slash in assetBase must not produce //components/ URLs.',
        );
    }

    public function testAutoloaderGrammarPathIsPinnedExplicitly(): void
    {
        $this->assertStringContainsString(
            "autoloader.languages_path = base + '/components/';",
            $this->script,
            'The autoloader guesses languages_path from document.currentScript. It must be '
            . 'pinned to the resolved vendor root, otherwise every grammar request 404s.',
        );
        $this->assertStringContainsString(
            'autoloader.use_minified = true;',
            $this->script,
            'The vendor tree ships prism-<lang>.min.js; without this the autoloader asks for '
            . 'the unminified name and every grammar request 404s.',
        );
        $this->assertStringContainsString("+ '/components/'", $this->script);
    }

    public function testMissingVendorAssetsProduceAnActionableWarning(): void
    {
        $this->assertStringContainsString(
            'npm:install prismjs',
            $this->script,
            'When the vendor tree is missing the console warning must name the exact command '
            . 'that fixes it, otherwise the report is just "no highlighting".',
        );
        $this->assertStringContainsString(
            'corePromise = null',
            $this->script,
            'A failed core load must reset the shared promise, otherwise installing the '
            . 'package and reloading the SPA without a full page reload stays broken.',
        );
    }

    public function testThemeStylesheetIsSanitizedAndDeduplicated(): void
    {
        $this->assertStringContainsString(
            "if (!/^[a-z0-9-]+$/i.test(theme",
            $this->script,
            'The theme prop is interpolated into a URL; it must be validated against a '
            . 'strict charset before it reaches the DOM.',
        );
        $this->assertStringContainsString(
            'link[data-prism-css^="theme-"]',
            $this->script,
            'A theme switch must drop the previously injected theme link. Both sheets style '
            . 'the same .token selectors with equal specificity, so the later link wins and '
            . 'a stale one turns A -> B -> A into a silent no-op.',
        );
        $this->assertStringContainsString(
            'data-prism-css',
            $this->script,
            'Injected stylesheets must be marked so a second PhCode on the page does not '
            . 're-inject the same theme.',
        );
    }

    public function testScriptRequestsAreSingleFlightAndRecoverable(): void
    {
        $this->assertStringContainsString(
            'const inflight = new Map();',
            $this->script,
            'Script loads must be memoised by URL: a page with N PhCode blocks has to issue '
            . 'one request per asset, not N.',
        );
        $this->assertStringContainsString(
            'inflight.delete(url);',
            $this->script,
            'A rejected load must be evicted from the memo, otherwise every later block '
            . 'inherits the same rejection and no amount of fixing the server helps.',
        );
    }

    // ------------------------------------------------------------ highlight

    public function testGrammarIsAwaitedBeforeHighlighting(): void
    {
        $loadPos = $this->pos('loadLanguages(');
        $highlightPos = $this->pos('highlightElement(');

        $this->assertNotFalse($loadPos, 'PhCode must preload the grammar via the autoloader.');
        $this->assertNotFalse($highlightPos, 'PhCode must call Prism.highlightElement.');
        $this->assertLessThan(
            $highlightPos,
            $loadPos,
            'The grammar must be loaded BEFORE highlighting. The autoloader only reacts to '
            . 'Prism\'s "complete" hook (i.e. after a pass), so highlighting first renders '
            . 'raw text and nothing re-runs it.',
        );
        $this->assertStringContainsString(
            'await withTimeout(ensureGrammar(this.prismLanguage))',
            $this->script,
            'The grammar preload must be awaited in the highlight path, not fire-and-forget.',
        );
    }

    public function testHighlightResyncsTheCodeElementFromTheProp(): void
    {
        $this->assertMatchesRegularExpression(
            '/el\.textContent\s*=\s*this\.code/',
            $this->script,
            'Every highlight pass must re-seed the element with the raw code: Prism '
            . 'rewrites innerHTML, so a language switch would otherwise re-tokenize '
            . 'Prism\'s own markup.',
        );
    }

    public function testLineNumbersClassIsReappliedBeforeEachPass(): void
    {
        $this->assertStringContainsString(
            "el.classList.toggle('line-numbers', this.lineNumbers)",
            $this->script,
            'The line-numbers plugin removes the class from <code> after each pass and Vue '
            . 'will not re-patch an unchanged class binding, so the pass would silently '
            . 'lose line numbers on the second render.',
        );
    }

    public function testPlainLanguagesNeverRequestAGrammar(): void
    {
        $this->assertStringContainsString(
            'PLAIN_LANGUAGES',
            $this->script,
            'PhCode defaults to language "text"; Prism has no text/plain component, so the '
            . 'guard is what keeps the default prop from firing a guaranteed 404.',
        );
    }

    public function testAssetFailureDegradesToPlainTextWithoutThrowing(): void
    {
        $this->assertMatchesRegularExpression(
            '/if \(!\(await this\.loadPrism\(\)\)\) \{\s*this\.highlighted = false;\s*return;/',
            $this->script,
            'When the vendor assets are missing, highlighting must bail out early: Prism.highlightElement '
            . 'on a missing Prism is a hard TypeError.',
        );
        $this->assertStringNotContainsString(
            'Prism.highlightAll',
            $this->script,
            'highlightAll() rescans the whole document and would drag in every other '
            . 'framework-owned block; PhCode owns exactly one element.',
        );
    }

    public function testPropChangesRethrowTheHighlightAndUnwatchOnUnmount(): void
    {
        $this->assertStringContainsString(
            "() => [this.code, this.language, this.lineNumbers, this.theme, this.assetBase, this.activeTheme]",
            $this->script,
            'A code, language, lineNumbers or theme change must re-run the highlight pass.',
        );
        $this->assertStringContainsString('beforeUnmount()', $this->script);
        $this->assertStringContainsString('for (const stop of this.unwatch)', $this->script);
    }

    public function testThemePickerIsBuiltOnTheSharedDropdownAndSitsBeforeTheCopyButton(): void
    {
        $this->assertStringContainsString(
            '<ph-dropdown',
            $this->template,
            'The picker must reuse the framework dropdown instead of hand-rolling a menu: that is '
            . 'what gives it the outside-click, Escape and Bootstrap dark/light behaviour.',
        );
        $this->assertStringContainsString(':items="themeItems"', $this->template);
        $this->assertStringContainsString('align="end"', $this->template);

        $this->assertLessThan(
            strpos($this->template, 'ph-code-copy'),
            strpos($this->template, '<ph-dropdown'),
            'The theme picker is placed before the copy icon, not after it.',
        );
    }

    public function testThemePickerNeverWritesToTheThemeProp(): void
    {
        $this->assertStringContainsString('activeTheme: this.theme', $this->script);

        $this->assertStringNotContainsString(
            'this.theme =',
            $this->script,
            'Writing to a prop is a Vue anti-pattern: it is silently dropped on re-render and '
            . 'bites whoever passes :theme from the page.',
        );

        $this->assertStringContainsString('this.activeTheme = item.value;', $this->script);
        $this->assertStringContainsString('this.$emit(\'theme-change\', item.value)', $this->script);

        $this->assertStringContainsString(
            'this.$watch(() => this.theme, (value) => { this.activeTheme = value; })',
            $this->script,
            'A theme change coming from the page must still win over the local pick, and it needs '
            . 'its own watcher: folding it into the combined watcher would compare the local '
            . 'selection against the prop and undo every click the user just made.',
        );
    }

    public function testThemePickerAppliesTheSelectionThroughTheScopedThemeLoader(): void
    {
        // The theme is applied per instance now, so the contract is not "one
        // shared <link>" but "a sheet scoped to this block, replacing its own
        // previous one". A picker click must go through that path, or the block
        // keeps the sheet it had.
        $this->assertStringContainsString(
            'await this.loadTheme()',
            $this->script,
            'The active theme must be applied through the scoped loader; that is what drops the '
            . 'instance\'s previous sheet instead of leaving two competing ones behind.',
        );

        $this->assertStringContainsString(
            'applyScopedTheme(this.assetRoot, this.activeTheme, this.scopeId)',
            $this->script,
            'The theme must be scoped to this instance. A page-global sheet would repaint every '
            . 'other <ph-code> on the page, which is the bug per-instance theming exists to fix.',
        );

        $this->assertStringContainsString(
            'data-ph-theme-scope',
            $this->script,
            'The block must carry the scope attribute the rewritten selectors hang off.',
        );

        // A self-referential fallback would reintroduce exactly the shared-sheet
        // coupling above, so the page-wide path has to stay reachable ONLY as a
        // failure mode, never as the normal one.
        $this->assertMatchesRegularExpression(
            '/loadTheme\(\)[\s\S]*?catch[\s\S]*?applyGlobalThemeFallback/',
            $this->script,
            'The page-wide <link> must be a fallback for a failed fetch, not the normal path.',
        );

        $this->assertMatchesRegularExpression(
            "/const THEMES = \[[^\]]*'tomorrow'[^\]]*\];/",
            $this->script,
            'The picker list must contain the theme the component defaults to, otherwise the '
            . 'trigger button shows a theme the user cannot re-select.',
        );

        $this->assertStringContainsString(
            "icon: name === this.activeTheme ? 'check-lg' : null",
            $this->script,
            'The menu has to mark the active theme, otherwise the trigger label is the only '
            . 'feedback and the menu gives no idea what is currently applied.',
        );
    }

    public function testLanguageAliasesResolveToPrismGrammarNames(): void
    {
        foreach (["js: 'javascript'", "html: 'markup'", "dockerfile: 'docker'", "sh: 'bash'", "md: 'markdown'"] as $alias) {
            $this->assertStringContainsString(
                $alias,
                $this->script,
                "PhCode must canonicalise the {$alias} alias: the <code> class and the "
                . 'preloaded grammar have to agree, otherwise the autoloader fetches a '
                . 'grammar the element never asks for and the block stays unhighlighted.',
            );
        }

        $this->assertStringContainsString(
            'return aliases[normalized] || normalized',
            $this->script,
            'Unknown languages must pass through untouched so arbitrary Prism grammars '
            . 'keep working.',
        );
    }

    public function testNoBareInstanceHelpersInTheScriptBlock(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/(^|[^-.\w])\$(props|emit|refs|el|nextTick|attrs|slots|watch)\b/',
            $this->script,
            'The <script> block is served verbatim as a module: instance helpers are only '
            . 'reachable through $this.',
        );
    }

    // --------------------------------------------------------------- module

    public function testModuleIsBuiltAndSyntacticallyValid(): void
    {
        $this->assertStringContainsString(
            'const DEFAULT_ASSET_BASE',
            $this->module,
            'The served module must carry the runtime loader verbatim.',
        );

        AssertsPhueJsModuleContract::assertEmittedModuleIsLoadableJs(
            $this,
            $this->module,
            'PhCode',
        );
    }

    public function testPendingStateIsCosmeticAndAlwaysReleased(): void
    {
        $this->assertStringContainsString(
            'opacity: this.pending ? 0.55 : 1',
            $this->script,
            'The block is server rendered and readable, so the load flash is smoothed with a soft '
            . 'pending opacity rather than v-cloak (hides real content) or a skeleton (replaces '
            . 'readable code with a placeholder).',
        );

        $this->assertMatchesRegularExpression(
            '/\}\s*finally \{\s*\/\/ Always, in a finally.*?this\.pending = false;/s',
            $this->script,
            'pending must be cleared in a finally: language="text" and a missing vendor tree both '
            . 'skip the success path, and would otherwise leave the block permanently dimmed.',
        );

        $this->assertStringNotContainsString(
            "this.pending = this.highlighted !== '1'",
            $this->script,
            'pending is a "first pass settled" flag, not a "highlighted" flag.',
        );

        // The dark background comes from the theme sheet, so it has to be in the
        // page before Prism finishes. A scoped theme must be FETCHED as text to
        // be rewritten, so it costs a round trip the old <link> never paid — the
        // way to keep the "white block, then it jumps dark" flash away is to have
        // the two requests in flight together, not to await one before starting
        // the other. Both must therefore be started before the first await.
        $coreStart = strpos($this->script, 'const coreTask = withTimeout(ensureCore(base))');
        $themeStart = strpos($this->script, 'await this.loadTheme()');
        $coreAwait = strpos($this->script, 'await coreTask');

        $this->assertNotFalse($coreStart, 'Prism core must be kicked off explicitly so it can overlap the theme fetch.');
        $this->assertNotFalse($themeStart);
        $this->assertNotFalse($coreAwait);

        $this->assertLessThan(
            $themeStart,
            $coreStart,
            'Core must be STARTED before the theme is awaited; awaiting the theme first would serialise '
            . 'the two requests and reintroduce the white-block flash the theme was meant to prevent.',
        );

        $this->assertLessThan(
            $coreAwait,
            $themeStart,
            'Core must be started but not awaited before the theme lands, for the same reason.',
        );
    }

    public function testWrapperNeverBecomesAClippingContextForTheMenu(): void
    {
        $start = strpos($this->template, 'data-phui="code"');
        $this->assertNotFalse($start, 'The PhCode wrapper must be identifiable in the template.');
        $wrapper = substr($this->template, max(0, $start - 300), 300);

        $this->assertStringContainsString(
            'position-relative',
            $wrapper,
            'The wrapper has to be the positioning context of the absolutely placed dropdown menu.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/overflow-(hidden|clip|auto|scroll)\b/',
            $wrapper,
            'overflow on the wrapper clips the dropdown menu: it is absolutely positioned inside '
            . 'the header, so anything but visible makes the bottom of the list unreachable. This '
            . 'shipped as a visible bug, hence the guard.',
        );

        // Clipping used to provide the rounded corners; without it each painted
        // child has to round its own edges or the block ends up with square corners.
        $this->assertStringContainsString(
            'rounded-top',
            $this->template,
            'The header paints a background, so it carries the top radius itself.',
        );
        $this->assertStringContainsString(
            'rounded-bottom',
            $this->template,
            'The <pre> is painted by the Prism theme (pre[class*=language-] gets a background), '
            . 'so it carries the bottom radius itself.',
        );
    }

    public function testComponentLoadsThroughThePhuiLoader(): void
    {
        $loader = TestComponentLoader::make(dirname(self::PHCODE));
        $component = $loader->load('PhCode');

        $this->assertSame('Code Block', $component->title);
        $this->assertStringContainsString('ph-code', $component->view->getTemplate());
        $this->assertStringContainsString('highlightElement', (string) $component->view->getInlineScript());
        $this->assertNull($component->view->getHtml(), 'PhCode is a client component, not an HTML-first partial.');
    }
}
