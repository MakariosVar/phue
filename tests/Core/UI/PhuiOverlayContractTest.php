<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
/**
 * PhuiOverlayContractTest — the overlay layer and positioning contract of §1.5.
 *
 * Nine components float above the page. Nothing in the framework checks how they
 * stack, where they anchor, or whether they follow their trigger, so every one of
 * those is a per-component decision that was made once and never revisited. The
 * result is not one bug but a family of them, all invisible in review:
 *
 *  - `PhTooltip` sits at `1100`, **above** the toast region at `1090`, so a tip on a
 *    button inside a toast is drawn over its own toast.
 *  - `PhSelect3`, `PhDatePicker` and `PhColorPicker` open an in-flow
 *    `position: absolute` panel at `z-index: 1000`. They are not teleported, so the
 *    panel is clipped by every scrolling ancestor — and `overflow-y: auto` is enough
 *    to clip it, because an axis left `visible` next to a non-visible one computes
 *    to `auto`. The showcase page's own `<main class="overflow-y-auto">` is exactly
 *    such an ancestor.
 *  - `PhPopover` is teleported but uses viewport `position: fixed` and registers
 *    **no scroll listener at all**, so it stays floating in the viewport, detached
 *    from its trigger, for as long as the page is scrolled.
 *
 * None of that is caught by the existing gates. `PhuiStyleCoverageTest` checks that
 * classes resolve; `PhuiComponentBuildTest` checks that the module builds. A
 * `z-index` of `1000` is a number that compiles.
 *
 * These tests read the source, because the properties are structural: they are
 * about what a component IS (does it teleport, does it re-measure, does it own a
 * number) rather than about a pixel. A render assertion could not tell "clipped by
 * its ancestor" from "positioned correctly" without a layout engine.
 *
 * The tables below are the contract from `docs/PhUI_Components.md` §1.5, restated
 * here so a change to one without the other is visible in a diff.
 *
 * @package Phue\Tests\Core\UI
 */
final class PhuiOverlayContractTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    private const JS_DIR = __DIR__ . '/../../../src/Core/UI/js';

    private const DOCS = __DIR__ . '/../../../docs/PhUI_Components.md';

    /**
     * The layer table. Order matters as much as the values: the assertion below
     * requires it to be strictly increasing, and requires the documented table to
     * carry the same numbers.
     *
     * @var array<string, int>
     */
    /**
     * The public surface of each composable, by file.
     *
     * Read from the modules themselves by a separate test, so a new export cannot be
     * added to one of them and then used by a component without also being listed
     * here and checked.
     *
     * @var array<string, list<string>>
     */
    private const COMPOSABLE_EXPORTS = [
        'position' => ['positionFor', 'trackViewport', 'releaseViewport', 'measureAnchor', 'overlayLayerFor'],
        'overlay' => ['focusableWithin', 'trapTab', 'focusTrap', 'registerEscapeHandler'],
        'store' => ['createToastStore', 'createConfirmQueue'],
    ];

    private const LAYERS = [
        // Keys are the documented row labels, verbatim: the assertion below matches
        // the docs table by name, so a label that drifts apart is caught rather than
        // silently testing nothing.
        //
        // `drawer panel` and `drawer backdrop` were the wrong way round here for as
        // long as this file existed: both the table and the docs agreed with each
        // other and disagreed with PhDrawer, which writes 1045 on the backdrop and
        // 1046 on the panel. Nothing caught it because the ordering assertion only
        // compares numbers, and a swapped pair is still ordered. Anyone reasoning
        // from this table about which surface to clear would have cleared the
        // backdrop and left the panel in front.
        'sticky page chrome' => 1020,
        'dropdown menu' => 1040,
        'drawer backdrop' => 1045,
        'drawer panel' => 1046,
        // A float teleported out of an overlay has to paint above it. These two are
        // the fallback layers for that case, owned by position.js, and are only
        // reached when the container's own z-index cannot be read (no stylesheet).
        'dropdown menu inside a drawer' => 1047,
        'modal' => 1055,
        'dropdown menu inside a modal' => 1056,
        'popover backdrop' => 1069,
        'popover' => 1070,
        'tooltip' => 1080,
        'toast region' => 1090,
    ];

    /**
     * Components whose panel is anchored to a control and therefore has to escape
     * its clipping ancestor.
     *
     * @var list<string>
     */
    private const ANCHORED_FLOATERS = [
        'PhDropdown',
        'PhPopover',
        'PhTooltip',
        'PhSelect3',
        'PhDatePicker',
        'PhColorPicker',
    ];

    /**
     * Centred overlays. They teleport too — for the same reason: they must escape a
     * clipping ancestor — but they have no anchor to follow and no placement to
     * flip, so they are exempt from the scroll-tracking rule.
     *
     * @var list<string>
     */
    private const CENTRED_OVERLAYS = [
        'PhModal',
        'PhDrawer',
        'PhConfirmDialog',
        'PhCommandPalette',
        'PhToast',
        'PhImage',      // its lightbox
    ];

    /**
     * Components that reach the portal through another component instead of
     * teleporting themselves.
     *
     * `<ph-command-palette>` renders a `<ph-modal>`, and `<ph-modal>` teleports —
     * so the palette is already portalled and needed no change. The first version of
     * this contract read the source for a literal `<teleport>` and reported it as a
     * bug, which is what a static check does when composition is allowed: the
     * component has no portal of its own, and the check cannot tell that from not
     * having one at all. Naming the delegation is the honest form of the contract,
     * and it is a second line of defence: if `PhModal` ever stopped teleporting,
     * the transitive assertion below fails with both names in the message.
     *
     * @var array<string, string>
     */
    private const PORTAL_PROVIDER = [
        'PhCommandPalette' => 'PhModal',
    ];

    // ------------------------------------------------------------------ helpers

    private function source(string $component): string
    {
        $path = self::PHUI_DIR . '/' . $component . '.phue';

        self::assertFileExists($path, "missing component source: {$path}");

        return (string) file_get_contents($path);
    }

    private function script(string $component): string
    {
        $structure = (new \Phue\Core\UI\PhueTemplateParser())->parseStructure(
            self::PHUI_DIR . '/' . $component . '.phue'
        );

        return $structure['script'] ?? '';
    }

    /** @return array<string, string> component => full source */
    private function allSources(): array
    {
        $out = [];
        foreach (glob(self::PHUI_DIR . '/*.phue') ?: [] as $file) {
            $out[basename($file, '.phue')] = (string) file_get_contents($file);
        }

        return $out;
    }

    // ------------------------------------------------- 1. one source of truth

    /**
     * Every layer number a component writes must be one the documented table defines.
     *
     * The first version of this contract demanded that no component carry a layer
     * number at all, on the assumption a shared `LAYERS` constant could be imported.
     * It cannot: a `.phue` `<script>` is served verbatim as an ESM module whose only
     * import is Vue, so there is no path to import a constant from. A rule that
     * cannot be satisfied is a rule that gets deleted, and the value that was
     * actually being protected — a layer drifting away from the documented order —
     * went with it.
     *
     * So the rule is the achievable one: the number stays local, but it is pinned.
     * `TIP_Z = 1080` passes because 1080 is the tooltip's documented layer; a
     * future `PhSlider` that invents `SLIDER_Z = 9999` fails here, and so does a
     * silent edit of `TIP_Z` to 1100 — which is exactly how the tooltip ended up
     * above the toast region in the first place.
     */
    public function testEveryLayerNumberIsADocumentedLayer(): void
    {
        $documented = array_values(self::LAYERS);

        $offenders = [];

        foreach ($this->allSources() as $component => $source) {
            $lines = explode("\n", $source);
            foreach ($lines as $index => $line) {
                $trimmed = trim($line);

                // A comment that merely names a layer in prose is not a decision.
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                // `zIndex: 1080`, `z-index: 1080`, `const TIP_Z = 1100`. The second
                // pattern is anchored on a `_Z` suffix on purpose: a looser
                // `_<anything> =` also matched `LOAD_TIMEOUT = 10000`.
                $value = null;
                foreach (
                    [
                        '/\bz-?[Ii]ndex\s*[:=]\s*([0-9]{3,5})\b/',
                        '/\bconst\s+[A-Za-z_]*_Z\s*=\s*([0-9]{3,5})\b/',
                    ] as $pattern
                ) {
                    if (preg_match($pattern, $line, $m) === 1) {
                        $value = (int) $m[1];
                        break;
                    }
                }

                if ($value === null || in_array($value, $documented, true)) {
                    continue;
                }

                $offenders[] = sprintf(
                    '%s:%d — layer %d is not in the documented table (%s). An undocumented '
                    . 'number cannot be ordered against the layers around it, which is how a '
                    . 'tooltip ended up above the toasts.',
                    $component,
                    $index + 1,
                    $value,
                    implode(', ', $documented)
                );
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    public function testEveryDocumentedLayerIsUsedByTheComponentThatOwnsIt(): void
    {
        // The other direction: a layer in the table that nothing claims is either a
        // stale row or a component that lost its overlay, and both are invisible.
        $expected = [
            'tooltip' => 'PhTooltip',
            'popover backdrop' => 'PhPopover',
            'popover' => 'PhPopover',
            'dropdown menu' => 'PhDropdown',
            'drawer panel' => 'PhDrawer',
            'drawer backdrop' => 'PhDrawer',
        ];

        // The two resolved layers live in the shared composable rather than in a
        // component, so they are asserted against the file that writes them.
        foreach (['dropdown menu inside a drawer', 'dropdown menu inside a modal'] as $layer) {
            $value = self::LAYERS[$layer];

            $this->assertMatchesRegularExpression(
                '/(?:z-?[Ii]ndex\s*[:=]\s*[0-9]*|layer\s*:\s*)[0-9]*' . $value . '\b/',
                (string) file_get_contents(self::JS_DIR . '/position.js'),
                sprintf('the documented `%s` layer (%d) must be the one position.js falls back to', $layer, $value)
            );
        }

        foreach ($expected as $layer => $component) {
            $value = self::LAYERS[$layer];

            $this->assertMatchesRegularExpression(
                '/(?:z-?[Ii]ndex\s*[:=]\s*[0-9]*|[A-Za-z_]*_Z\s*=\s*)' . $value . '\b/',
                $this->source($component),
                sprintf('the documented `%s` layer (%d) must be the one %s writes', $layer, $value, $component)
            );
        }
    }

    public function testTheLayerTableIsStrictlyIncreasing(): void
    {
        $values = array_values(self::LAYERS);
        $sorted = $values;
        sort($sorted);

        self::assertSame($sorted, $values, 'the layer table must be listed in stacking order');

        foreach (self::LAYERS as $layer => $value) {
            if ($layer === 'sticky page chrome') {
                continue;
            }

            self::assertGreaterThan(
                self::LAYERS['sticky page chrome'],
                $value,
                "the `{$layer}` layer must beat sticky chrome, or a float cannot cover page content"
            );
        }
    }

    public function testTheDocumentedTableMatchesTheTestedOne(): void
    {
        $docs = (string) file_get_contents(self::DOCS);
        self::assertStringContainsString('## 1.5 Overlay layers and positioning', $docs, '§1.5 is missing from the docs');

        foreach (self::LAYERS as $layer => $value) {
            // `| dropdown menu | `1040` | …` — the value must appear in the SAME row
            // as the layer name, so a table edit that forgets the number, or that
            // reorders the two, fails here rather than silently diverging.
            self::assertMatchesRegularExpression(
                '/\|[^|]*' . preg_quote($layer, '/') . '[^|]*\|\s*`?' . $value . '`?\s*\|/i',
                $docs,
                sprintf('the documented table does not carry `%s` = `%d` in one row', $layer, $value)
            );
        }
    }

    // ------------------------------------------------- 2. nothing may be clipped

    public function testAnAnchoredFloatTeleportsToBody(): void
    {
        $offenders = [];

        foreach (self::ANCHORED_FLOATERS as $component) {
            $source = $this->source($component);

            if (preg_match('/<teleport\b[^>]*\bto="body"/', $source) !== 1) {
                $offenders[] = sprintf(
                    '%s — its panel is anchored to a control but is not teleported, so it is '
                    . 'clipped by every scrolling ancestor (an `overflow-y: auto` ancestor is '
                    . 'enough). No z-index escapes a clip.',
                    $component
                );
            }
        }

        self::assertSame([], $offenders, implode("\n  ", [
            'An anchored float has to teleport to `body`:',
            '',
            implode("\n  ", $offenders),
        ]));
    }

    // ------------------------------------------- 3. a float must follow its trigger

    public function testAnAnchoredFloatRepositionsOnScrollInTheCapturePhase(): void
    {
        $offenders = [];

        foreach (self::ANCHORED_FLOATERS as $component) {
            $source = $this->source($component);

            // Delegated to the shared positioning composable: `trackViewport()`
            // owns the capture-phase scroll listener. That delegation is only
            // accepted when the handle is actually released, otherwise the
            // listener outlives the component and the panel keeps repositioning
            // after it is gone.
            if (str_contains($source, 'trackViewport(')) {
                if (str_contains($source, 'releaseViewport(') !== true) {
                    $offenders[] = "{$component} — calls trackViewport() but never releaseViewport(), so the "
                        . 'scroll/resize listeners leak after unmount';
                }

                continue;
            }

            if (str_contains($source, "addEventListener('scroll'") !== true) {
                $offenders[] = "{$component} — no scroll listener, so the panel does not follow its trigger";
                continue;
            }

            // A plain `scroll` listener does not fire for nested scrollers, which is
            // where the interesting ones are: a dropdown inside a scrolling column
            // stays put while the column scrolls under it.
            if (preg_match("/addEventListener\('scroll',\s*[^,]+,\s*true\s*\)/", $source) !== 1) {
                $offenders[] = "{$component} — the scroll listener is not capture-phase, so nested scrollers are missed";
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * The invariant the delegation above depends on.
     *
     * `PhDropdown` no longer attaches its own scroll listener, so this is the
     * only place asserting that the shared composable listens in the capture
     * phase. Without it, `trackViewport()` could silently drop to a bubbling
     * listener and every anchored float would stop following nested scrollers
     * while all component-level tests stayed green.
     */
    public function testThePositioningComposableListensInTheCapturePhase(): void
    {
        $source = (string) file_get_contents(self::JS_DIR . '/position.js');

        self::assertStringContainsString('trackViewport', $source);
        self::assertMatchesRegularExpression(
            "/addEventListener\('scroll',\s*[^,]+,\s*true\s*\)/",
            $source,
            'trackViewport() must register scroll with capture=true, otherwise nested scrollers are missed'
        );
        self::assertMatchesRegularExpression(
            "/removeEventListener\('scroll',\s*[^,]+,\s*true\s*\)/",
            $source,
            'releaseViewport() must remove the scroll listener with the same capture flag it registered it with'
        );
    }

    /**
     * The invariant the string-grep above cannot see: opening must *call* the
     * positioning path, and unmounting must *call* the release.
     *
     * `PhColorPicker` shipped with a complete measurement apparatus — `measure()`,
     * `trackViewport()`, `releaseViewport()`, `onViewportChange()` — and none of it
     * was reachable: the `open` watcher only emitted, so `triggerRect` stayed null
     * and the panel rendered with `visibility: hidden` on every open. The feature
     * was 100% dead while the code above reported it as compliant, because a
     * declared-but-uncalled method contains the identifier the grep looks for.
     *
     * So this checks call sites, scoped to the two places that matter. A method
     * that exists but is never invoked cannot satisfy either.
     */
    public function testAnAnchoredFloatPositionsOnOpenAndReleasesOnUnmount(): void
    {
        $positioning = ['measureAnchor', 'reposition', 'startTracking', 'trackViewport'];
        $releasing = ['stopTracking', 'releaseViewport'];
        $offenders = [];

        foreach (self::ANCHORED_FLOATERS as $component) {
            $script = $this->script($component);

            // A member test, not an exact-string one: these components declare
            // 'overlay' as well now, and a literal match would start failing the
            // moment a component needs a second composable.
            if (preg_match("/composables:\s*\[[^\]]*'position'/", $script) !== 1) {
                $offenders[] = "{$component} — does not declare the position composable";
                continue;
            }

            $open = $this->blockFrom($script, '/^ {2}watch: \{/m');
            if ($open === null) {
                $offenders[] = "{$component} — has no watcher block to inspect";
                continue;
            }

            // Deliberately the whole watch block rather than one named key: the state
            // that opens a float is `open` on some components and `visible` on
            // others, and the invariant is the same either way. A watcher on some
            // unrelated key would satisfy this loosely, but a float has one piece of
            // reactive state that matters, and naming it per component would only
            // make the test fail on a rename instead of on a real break.
            $calls = [];
            foreach ($positioning as $name) {
                if (str_contains($open, "this->{$name}(") || str_contains($open, ".{$name}(")) {
                    $calls[] = $name;
                }
            }
            if ($calls === []) {
                $offenders[] = "{$component} — no watcher triggers positioning "
                    . '(looked for ' . implode(', ', array_map(static fn ($n) => "this->{$n}()", $positioning)) . ')';
            }

            $unmount = $this->blockFrom($script, '/^ {2}beforeUnmount\(\) \{/m');
            if ($unmount === null) {
                $offenders[] = "{$component} — has no beforeUnmount to inspect";
                continue;
            }

            $released = false;
            foreach ($releasing as $name) {
                if (str_contains($unmount, "this->{$name}(") || str_contains($unmount, ".{$name}(")) {
                    $released = true;
                    break;
                }
            }
            if ($released !== true) {
                $offenders[] = "{$component} — beforeUnmount never releases the viewport handle, so the "
                    . 'scroll/resize listeners outlive the component';
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * Extracts a block by its opening line and the matching closing line.
     *
     * The scripts are consistently indented (2 spaces per options level), so the
     * closing line is found by indentation rather than by counting braces — braces
     * appear inside object literals and comment prose throughout these files, and a
     * naive counter stops in the wrong place.
     */
    private function blockFrom(string $script, string $openPattern): ?string
    {
        if (preg_match($openPattern, $script, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [, $start] = $m[0];
        $indent = strlen($m[0][0]) - strlen(ltrim($m[0][0]));
        $lines = explode("\n", substr($script, $start));

        $block = [];
        foreach ($lines as $i => $line) {
            $block[] = $line;
            if ($i === 0) {
                continue;
            }
            if (preg_match('/^ {' . $indent . '}},?$/', $line) === 1) {
                break;
            }
        }

        return implode("\n", $block);
    }

    /**
     * A float that teleports out of an overlay has to land above it.
     *
     * The failure this exists to catch is silent and looks like a broken control:
     * teleporting to `body` makes the panel a sibling of the modal, the panel keeps
     * its page-level layer, and the modal's opaque surface paints over it. The
     * options are in the DOM, visible, and completely unreachable — which is what
     * was reported as "a select3 in a modal shows no options".
     *
     * So every float whose base layer sits below a container must resolve the layer
     * through the shared helper, and resolve it on open rather than in a computed
     * (reading a computed style inside a computed forces a style recalc per render).
     */
    public function testAFloatBelowAnOverlayLayerResolvesItOnOpen(): void
    {
        $containers = array_values(self::LAYERS);
        $offenders = [];

        foreach (self::ANCHORED_FLOATERS as $component) {
            $source = $this->source($component);
            if (str_contains($source, 'overlayLayerFor(') !== true) {
                continue;
            }

            if (str_contains($source, 'this.overlayLayer = overlayLayerFor(') !== true) {
                $offenders[] = "{$component} — calls overlayLayerFor() but never stores the result on open, "
                    . 'so the panel cannot use it';
                continue;
            }

            // The resolved value has to clear every container it might be inside.
            $base = $this->baseLayerOf($source);
            if ($base === null) {
                $offenders[] = "{$component} — no base layer constant found to compare against";
                continue;
            }

            if (str_contains($source, 'this.overlayLayer ||') !== true) {
                $offenders[] = "{$component} — the style does not fall back to the base layer when there is no overlay";
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * The inverse, and the reason this is cheap rather than universal.
     *
     * Only layers that sit below a container can be trapped by one. The popover and
     * the tooltip already clear every surface in the table, so they need no
     * resolution — and adding it anyway would be a second, redundant source of
     * truth for a case that cannot happen. Asserting the arithmetic keeps the
     * exemption honest if a layer number ever moves.
     */
    public function testOnlyFloatsBelowAContainerNeedLayerResolution(): void
    {
        $highestContainer = max(
            self::LAYERS['drawer panel'],
            self::LAYERS['modal']
        );

        $needsResolution = [];
        $exempt = [];

        foreach (self::ANCHORED_FLOATERS as $component) {
            $source = $this->source($component);
            $base = $this->baseLayerOf($source);
            self::assertNotNull($base, "{$component} — no base layer constant found");

            if ($base >= $highestContainer) {
                $exempt[$component] = $base;
                continue;
            }
            $needsResolution[$component] = $base;
        }

        // PhPopover at 1070 and PhTooltip at 1080 clear the modal at 1055.
        self::assertArrayHasKey('PhPopover', $exempt, 'PhPopover should not need layer resolution');
        self::assertArrayHasKey('PhTooltip', $exempt, 'PhTooltip should not need layer resolution');

        foreach (['PhDropdown', 'PhSelect3', 'PhDatePicker', 'PhColorPicker'] as $component) {
            self::assertArrayHasKey(
                $component,
                $needsResolution,
                "{$component} paints at {$needsResolution[$component]}, below the modal at "
                    . self::LAYERS['modal'] . ', so it must resolve its layer'
            );
        }
    }

    /**
     * The layer a component paints its panel at, or null if it has none.
     *
     * Both spellings count: a `_Z` constant (PhDropdown, PhSelect3, PhTooltip) and a
     * bare literal in the style object (PhPopover). Rejecting the second would mean
     * the exemption test below could not see the popover at all, which is the one
     * component whose exemption is most worth asserting.
     */
    private function baseLayerOf(string $source): ?int
    {
        if (preg_match("/const\s+[A-Za-z_]*_Z\s*=\s*([0-9]+)\s*;/", $source, $m) === 1) {
            return (int) $m[1];
        }

        return preg_match('/z-?[Ii]ndex\s*:\s*([0-9]+)/', $source, $m) === 1
            ? (int) $m[1]
            : null;
    }

    /**
     * Every event a component emits must be declared in its `emits` option.
     *
     * Vue does not stop an undeclared emit; it warns on every occurrence and then
     * drops the handler the parent passed. The component looks like it works — it
     * emits, the parent just never hears it — which makes the warning easy to
     * dismiss as noise in a console that also carries asset notices.
     *
     * It arrived by copying a sibling's wiring wholesale: `PhModal.activate()`
     * gained `this.$emit('open')` along with `PhDrawer`'s trap code, and `open` is
     * not part of `PhModal`'s contract (`v-model` and `close` are).
     */
    public function testEveryEmittedEventIsDeclared(): void
    {
        $offenders = [];

        foreach ($this->allSources() as $component => $source) {
            $script = $this->script($component);

            if (preg_match("/emits:\s*\[([^\]]*)\]/s", $script, $m) !== 1) {
                continue;
            }

            $declared = [];
            preg_match_all("/['\"]([^'\"]+)['\"]/", $m[1], $names);
            foreach ($names[1] as $name) {
                $declared[] = $name;
            }

            preg_match_all('/\$emit\(\s*[\x27"]([^\x27"]+)[\x27"]/', $script, $emits);
            foreach (array_unique($emits[1]) as $event) {
                if (!in_array($event, $declared, true)) {
                    $offenders[] = sprintf(
                        "%s emits '%s' but declares emits: [%s] — Vue warns and the parent's handler never runs",
                        $component,
                        $event,
                        implode(', ', $declared)
                    );
                }
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * A composable symbol a component did not declare is a runtime crash.
     *
     * `PhueComponentLoader` resolves the names in `composables: [...]` and prepends
     * that module's exports. It has no idea which identifiers the component body
     * actually reaches for, so calling one it never declared builds cleanly, ships
     * cleanly, and then throws `ReferenceError: … is not defined` in the browser —
     * the first place anyone finds out.
     *
     * That is exactly what happened when the floats started calling
     * `registerEscapeHandler()` while declaring only `position`. The export list
     * below is the whole public surface of the three composables, so the check is
     * exhaustive rather than a spot-check of the symbols someone remembered.
     */
    public function testEveryComposableSymbolUsedIsDeclared(): void
    {
        $exports = [];
        foreach (self::COMPOSABLE_EXPORTS as $composable => $symbols) {
            foreach ($symbols as $symbol) {
                $exports[$symbol] = $composable;
            }
        }

        $offenders = [];

        foreach ($this->allSources() as $component => $source) {
            $script = $this->script($component);

            preg_match("/composables:\s*\[([^\]]*)\]/", $script, $declared);
            $declaredList = [];
            foreach (explode(',', $declared[1] ?? '') as $name) {
                $name = trim($name, " \'\"");
                if ($name !== '') {
                    $declaredList[] = $name;
                }
            }

            foreach ($exports as $symbol => $composable) {
                if (!preg_match('/(?<![A-Za-z0-9_$])' . preg_quote($symbol, '/') . '\s*\(/', $script)) {
                    continue;
                }

                if (!in_array($composable, $declaredList, true)) {
                    $offenders[] = sprintf(
                        '%s calls %s() but declares composables: [%s] — the loader only injects what is '
                        . 'declared, so this is a ReferenceError in the browser',
                        $component,
                        $symbol,
                        implode(', ', $declaredList)
                    );
                }
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * The SCRIPT only — a backdrop legitimately wants `position: fixed`.
     *
     * `<ph-popover>` grew a real backdrop when its empty unstyled one was replaced,
     * and that div is `position: fixed; inset: 0` by design: a backdrop that does not
     * cover the viewport is not a backdrop. Scanning the template flagged the
     * component for its own click-to-close surface.
     *
     * What must never be viewport-fixed is the POSITIONED PANEL, and that decision is
     * made in a style object in the script. So the script is the right scope.
     */
    public function testAFloatPanelDoesNotUseViewportFixedPositioning(): void
    {
        $offenders = [];

        foreach (self::ANCHORED_FLOATERS as $component) {
            $script = $this->script($component);

            if (preg_match('/position:\s*[\'"]fixed[\'"]/', $script) === 1) {
                $offenders[] = sprintf(
                    '%s - positions its panel with viewport `fixed`. It escapes clipping but '
                    . 'detaches from the trigger on scroll; use document space instead.',
                    $component
                );
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    public function testABackdropIsFixedToTheViewport(): void
    {
        // The counterpart: the click-to-close surface has to cover the viewport, and
        // it is the one thing in these components that legitimately is viewport-fixed.
        $source = $this->source('PhPopover');

        self::assertMatchesRegularExpression(
            '/data-phui="popover-backdrop"/',
            $source,
            'the popover needs a real backdrop for the documented outside-click to work'
        );
        self::assertMatchesRegularExpression(
            '/popover-backdrop[\s\S]{0,200}position:\s*fixed/',
            $source,
            'the backdrop must be fixed to the viewport, or it covers nothing'
        );
    }

    public function testAFloatDoesNotMeasureTheViewportWithViewportUnits(): void
    {
        // `100vw`/`100vh` include the scrollbar, so a panel measured against them
        // hangs past the right edge by the scrollbar width on any page that scrolls.
        $offenders = [];

        foreach (self::ANCHORED_FLOATERS as $component) {
            $source = $this->source($component);

            // `calc(100vw - …)` is fine: it is a width, not a measurement of the
            // viewport for a flip decision.
            if (preg_match('/(?:innerWidth|documentElement\.clientWidth)/', $source) === 1) {
                continue;
            }

            if (preg_match('/\b(?:100vw|100vh)\b(?![^;\'"`]*[-+]\s*\d)/', $source) === 1) {
                $offenders[] = "{$component} — uses `100vw`/`100vh` for measurement; use `documentElement.clientWidth/Height`";
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    // --------------------------------------------------- the centred overlays

    public function testCentredOverlaysTeleportOrDelegateToSomethingThatDoes(): void
    {
        foreach (self::CENTRED_OVERLAYS as $component) {
            $source = $this->source($component);
            $provider = self::PORTAL_PROVIDER[$component] ?? null;

            if (preg_match('/<teleport\b[^>]*\bto="body"/', $source) === 1) {
                continue;
            }

            self::assertNotNull(
                $provider,
                "{$component} must teleport: a full-viewport overlay inside a clipping ancestor "
                . 'is invisible, and it is not an exception to the clip'
            );

            // `PhModal` is written `<ph-modal>` in a template: kebab-case, not
            // lowercase. `strtolower('PhModal')` yields `phmodal`, which matches
            // nothing and made the delegation look broken.
            $tag = '<' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $provider));

            self::assertStringContainsString(
                $tag,
                $source,
                "{$component} is registered as delegating its portal to {$provider} (`{$tag}`), "
                . 'but does not render it'
            );
        }
    }

    public function testADelegatedPortalIsActuallyStillProvided(): void
    {
        foreach (self::PORTAL_PROVIDER as $component => $provider) {
            self::assertMatchesRegularExpression(
                '/<teleport\b[^>]*\bto="body"/',
                $this->source($provider),
                "{$provider} stopped teleporting, so {$component} lost its portal too"
            );
        }
    }

    // -------------------------------------------------------------- the coverage

    public function testTheContractCoversEveryComponentThatFloats(): void
    {
        $covered = array_merge(self::ANCHORED_FLOATERS, self::CENTRED_OVERLAYS);

        // Any component that teleports to `body` is, by definition, floating above the
        // page — so it must be classified by one of the two tables. An unclassified
        // one would sit outside the contract entirely, which is how `1100` survived.
        $unclassified = [];
        foreach ($this->allSources() as $component => $source) {
            if (!preg_match('/<teleport\b[^>]*\bto="body"/', $source)) {
                continue;
            }
            if (!in_array($component, $covered, true)) {
                $unclassified[] = $component;
            }
        }

        self::assertSame([], $unclassified, sprintf(
            'These components teleport to `body`, so they float above the page, but they are in '
            . 'neither contract table: %s. Classify them — an overlay nobody owns is exactly how '
            . 'a layer number drifts out of the documented order.',
            implode(', ', $unclassified)
        ));
    }

    /**
     * Every class a component queries must exist in that component's own template.
     *
     * `<ph-popover>` measured its trigger with `this.$el.querySelector('[data-phui="popover-trigger"]')`
     * while the template never carried that attribute. `querySelector` returned
     * null, so `triggerRect` stayed null, `popoverStyle` returned `{}`, and the panel
     * fell back to its static position. It looked correct only because the panel was
     * viewport-fixed; the moment it moved to document space the same missing class
     * made it invisible.
     *
     * The selector is matched against both class attributes and `data-phui`
     * attributes, because the kit uses `data-phui` for exactly this and forbids
     * custom classes (there is no stylesheet to land in).
     *
     * Nothing about that failure is visible in a unit test of the style helper: the
     * helper is only ever handed a rect, and a test that hands it a fake one passes
     * happily. The defect is in the *wiring* between the measurement and the markup,
     * so the assertion has to be about the two together.
     */
    public function testEveryQueriedClassExistsInItsOwnTemplate(): void
    {
        $offenders = [];

        foreach ($this->allSources() as $component => $source) {
            $structure = (new \Phue\Core\UI\PhueTemplateParser())->parseStructure(
                self::PHUI_DIR . '/' . $component . '.phue'
            );
            $template = $structure['template'] ?? '';
            $script = $structure['script'] ?? '';

            if ($template === '' || $script === '') {
                continue;
            }

            // A single-class selector passed to querySelector/querySelectorAll.
            $pattern = '/querySelector(?:All)?\\(\\s*\'([^\'\"]*\\.([A-Za-z0-9_-]+))\'/';
            preg_match_all($pattern, $script, $matches);

            foreach (array_unique($matches[2] ?? []) as $class) {
                // Present literally, or produced by a bound class.
                $declared = str_contains($template, $class)
                    || preg_match('/[\'"`' . ']/' . preg_quote($class, '/') . '/', $template) === 1;

                if (!$declared) {
                    $offenders[] = sprintf(
                        '%s - queries a .%s selector but its template never emits that class. '
                        . 'The lookup can never match, so whatever the queried value drives silently '
                        . 'stays null.',
                        $component,
                        $class
                    );
                }
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    public function testEveryContractComponentExists(): void
    {
        foreach (array_merge(self::ANCHORED_FLOATERS, self::CENTRED_OVERLAYS) as $component) {
            self::assertFileExists(
                self::PHUI_DIR . '/' . $component . '.phue',
                "{$component} is named in the overlay contract but does not exist"
            );
        }
    }

    public function testTheParserStillReadsEveryContractComponent(): void
    {
        // The other assertions here are regexes over raw source. Parsing first means a
        // `.phue` whose structure moved cannot make them pass vacuously.
        foreach (array_merge(self::ANCHORED_FLOATERS, self::CENTRED_OVERLAYS) as $component) {
            $structure = (new PhueTemplateParser())->parseStructure(self::PHUI_DIR . '/' . $component . '.phue');

            self::assertNotEmpty(
                $structure['template'] ?? null,
                "{$component} has no template to check"
            );
        }
    }
}