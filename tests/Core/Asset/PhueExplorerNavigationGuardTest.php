<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use Phue\Tests\Core\Stubs\PhueComponentRuntime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural contract for the phue-explorer click interceptor.
 *
 * PhueExplorerAssetTest locks this file with source-text assertions
 * (`assertStringContainsString("href.startsWith('#')")`), which cannot tell code
 * from prose: a guard that only exists inside a comment satisfies them, and a
 * guard that is deleted while its comment survives keeps them green. That is
 * exactly how `target="_blank"` shipped hijacked — the file carried a comment
 * saying the guard was "pending user implementation" and a passing test beside
 * it.
 *
 * So this file executes the real shipped asset instead. The IIFE only needs a
 * window, a document, a location and a fetch, all of which are modelled below,
 * and the `click` listener it registers is invoked directly with a synthetic
 * event. The assertion is the only thing that matters: **did the handler call
 * `preventDefault()`** (it hijacked the navigation) **and did it fetch**
 * (the SPA path ran)?
 *
 * The `fetch` double answers `{ ok: false }`, which sends `navigate()` down its
 * own hard-fallback branch (`window.location.href = url`) and out again, so no
 * DOM mounting is needed for the hijack/no-hijack verdict.
 *
 * Requirements (skipped, never failed, when absent): `bun` on PATH, which is how
 * the client runtime is executed in this repo.
 *
 * @package Phue\Tests\Core\Asset
 */
#[Group('integration')]
final class PhueExplorerNavigationGuardTest extends TestCase
{
    private const EXPLORER_JS = __DIR__ . '/../../../storage/assets/js/phue-explorer.js';

    /**
     * @var array<string, array{pass: bool, extra: string|null}>|null
     */
    private static ?array $results = null;

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!PhueComponentRuntime::available()) {
            self::$skipReason = 'bun is unavailable; cannot execute the interceptor.';

            return;
        }

        $source = (string) file_get_contents(self::EXPLORER_JS);

        $decoded = PhueComponentRuntime::run($source, self::harness(), self::prelude());

        $rows = $decoded['results'] ?? null;
        if (!is_array($rows) || $rows === []) {
            throw new \RuntimeException(
                'The interceptor harness returned no assertions: ' . json_encode($decoded, JSON_UNESCAPED_SLASHES)
            );
        }

        $results = [];
        foreach ($rows as $row) {
            $results[(string) $row['name']] = [
                'pass' => (bool) $row['pass'],
                'extra' => $row['extra'] ?? null,
            ];
        }

        self::$results = $results;
    }

    private function assertScenario(string $name, string $message): void
    {
        if (self::$results === null) {
            $this->markTestSkipped(self::$skipReason !== '' ? self::$skipReason : 'Runtime unavailable.');
        }

        $this->assertArrayHasKey($name, self::$results, "the harness never reported scenario \"{$name}\"");
        $row = self::$results[$name];

        $this->assertTrue(
            $row['pass'],
            $message . ($row['extra'] !== null ? "\nobserved: " . $row['extra'] : '')
        );
    }

    // ------------------------------------------------------- the SPA path runs

    public function testAPlainSameOriginLinkIsHijackedIntoAnSpaNavigation(): void
    {
        $this->assertScenario(
            'internal link is hijacked',
            'A same-origin left click on a plain anchor must preventDefault and fetch the page.',
        );
    }

    public function testAnExplicitSelfTargetIsStillHijacked(): void
    {
        $this->assertScenario(
            'target=_self is hijacked',
            'target="_self" means the same tab, so the SPA path must still run.',
        );
    }

    // ------------------------------------------------- the browser keeps it

    public function testABlankTargetIsLeftToTheBrowser(): void
    {
        $this->assertScenario(
            'target=_blank is left to the browser',
            'target="_blank" must never be preventDefault-ed into a same-tab SPA navigation.',
        );
    }

    public function testANamedBrowsingContextTargetIsLeftToTheBrowser(): void
    {
        $this->assertScenario(
            'target=_top is left to the browser',
            'Any target other than _self means "open elsewhere"; the interceptor must not take it over.',
        );
    }

    public function testADownloadLinkIsLeftToTheBrowser(): void
    {
        $this->assertScenario(
            'download is left to the browser',
            'A download link must never be fetched with X-Phue-SPA: the handler would get the JSON '
            . 'payload envelope instead of the file.',
        );
    }

    public function testRelExternalOptsOut(): void
    {
        $this->assertScenario(
            'rel=external is left to the browser',
            'rel="external" is the de-facto opt-out marker and must be honoured.',
        );
    }

    public function testTheDataNoSpaEscapeHatchOptsOut(): void
    {
        $this->assertScenario(
            'data-no-spa is left to the browser',
            'data-no-spa is the documented escape hatch for links the SPA must not own.',
        );
    }

    public function testNonHttpSchemesAreLeftToTheBrowser(): void
    {
        $this->assertScenario(
            'mailto: is left to the browser',
            'A mailto: link must reach the browser, not navigate().',
        );
    }

    public function testFragmentsAndJavascriptSchemesAreLeftToTheBrowser(): void
    {
        $this->assertScenario(
            'a fragment is left to the browser',
            'A "#anchor" link must stay a same-page scroll.',
        );
    }

    public function testCrossOriginLinksAreLeftToTheBrowser(): void
    {
        $this->assertScenario(
            'cross-origin is left to the browser',
            'The origin check must keep external links on the browser path.',
        );
    }

    public function testModifiedAndNonLeftClicksAreLeftToTheBrowser(): void
    {
        $this->assertScenario(
            'a ctrl-click is left to the browser',
            'Modifier keys mean "open in a new tab"; the interceptor must stay out of the way.',
        );
    }

    // ------------------------------------------------------------ the hardening

    public function testASvgAnchorCannotBreakTheInterceptor(): void
    {
        // `closest('a[href]')` is a *selector* match, not a type check: an SVG
        // `<a href>` matches it, and an SVGElement has neither `relList` nor a
        // `target` property. The handler is an async function, so a throw there
        // does not surface as an error in the console — it becomes a rejected
        // promise and every later click on the page silently stops navigating.
        //
        // The pinned verdict is "left to the browser": supporting SVG anchors
        // properly would mean reading `target` off a property they do not have
        // (so an SVG `_blank` link would be hijacked too), and the guard's own
        // philosophy is to let the browser own anything it cannot reason about.
        $this->assertScenario(
            'an SVG anchor neither throws nor navigates',
            'A non-HTMLAnchorElement matching a[href] must be handled without throwing, and must not '
            . 'be hijacked.',
        );
    }

    public function testARejectedUrlFallsBackToTheBrowser(): void
    {
        // 'http://' is one of the few inputs the URL parser actually rejects
        // (a malformed relative path like 'ht tp://%' is *not* rejected — it
        // resolves to a same-origin path and is correctly hijacked, which is
        // its own scenario). On rejection the catch block must leave the
        // default action alone.
        $this->assertScenario(
            'an unparseable href falls back to the browser',
            'A href the URL parser rejects must leave the default action intact.',
        );
    }

    // ------------------------------------------------- surviving the whole way

    public function testAWellFormedNavStillSyncsAfterThePageMounts(): void
    {
        // The happy path of the post-mount steps: the active nav item is marked
        // and `aria-current` is set. This is the baseline the next test measures
        // against — without it, "nothing broke" could also mean "nothing ran".
        $this->assertScenario(
            'a well-formed nav still syncs and the navigation survives',
            'After a successful navigation the active nav item must be marked active and given '
            . 'aria-current="page", with no reload and no console error.',
        );
    }

    public function testOneUnparseableNavHrefCannotTakeTheNavigationDown(): void
    {
        // navigate() wraps its whole body in one try/catch whose catch is
        // `window.location.href = url`. A throw in a *cosmetic* step therefore
        // does not degrade gracefully — it throws away a page that already
        // mounted correctly and hard-reloads the browser. One malformed href
        // anywhere in the nav is enough to do that on *every* navigation.
        $this->assertScenario(
            'one unparseable nav href cannot take the navigation down',
            'A nav item whose href the URL parser rejects must be skipped, not allowed to abort the '
            . 'navigation: no reload, no console error, and the remaining nav items still synced.',
        );
    }

    public function testANonHttpNavItemIsNeverMarkedActive(): void
    {
        // The same class of bug one function over: `new URL('mailto:…')` has no
        // origin and a nonsense pathname, so comparing it against the current
        // path is meaningless — and a nav item that cannot be SPA-navigated
        // cannot be the page you are on.
        $this->assertScenario(
            'a non-http nav item is never marked active',
            'A mailto:/tel: nav item must never be marked active and must never receive aria-current, '
            . 'and it must not disturb the rest of the sync.',
        );
    }

    /**
     * The platform doubles the asset runs against. Evaluated before the module
     * is imported, so the IIFE sees them as globals.
     */
    private static function prelude(): string
    {
        return <<<'JS'
const listeners = { document: {}, window: {} };
globalThis.__listeners = listeners;
globalThis.__fetchCalls = [];

// Knobs the scenarios turn between phases: phase 1 only needs to know *whether*
// the handler hijacked, phase 2 drives a full successful navigation so the
// post-mount steps (nav sync, scroll reset) actually run.
globalThis.__env = { fetchOk: false, nav: [], errors: [] };
globalThis.__app = {
  __vue_app__: null, firstChild: null,
  removeChild() {}, set innerHTML(v) { this._html = v; }
};
globalThis.__navOps = [];

globalThis.document = {
  addEventListener(type, fn) { (listeners.document[type] ||= []).push(fn); },
  getElementById(id) { return id === 'app' ? globalThis.__app : null; },
  createElement() { return {}; },
  head: { appendChild() {} },
  querySelector() { return null; },
  querySelectorAll() { return globalThis.__env.nav; },
  title: ''
};

globalThis.window = {
  location: { origin: 'https://shop.test', href: 'https://shop.test/orders' },
  history: { pushState() {} },
  addEventListener(type, fn) { (listeners.window[type] ||= []).push(fn); },
  dispatchEvent() {},
  scrollTo() {}
};

globalThis.fetch = (url, opts) => {
  globalThis.__fetchCalls.push({ url, headers: opts && opts.headers });
  // ok:false sends navigate() down its own hard-fallback branch and out again,
  // so the hijack/no-hijack verdict needs no DOM mounting.
  if (!globalThis.__env.fetchOk) {
    return Promise.resolve({ ok: false });
  }
  // ok:true runs the whole path, including the post-mount steps (nav sync,
  // scroll reset) where a throw is fatal to the navigation.
  return Promise.resolve({
    ok: true,
    json: () => Promise.resolve({ title: 'Products', html: '<p>mounted</p>', state: {}, scripts: [], navState: {} })
  });
};

// navigate() reports failures through console.error before falling back to a
// hard reload, so capture it: a scenario must be able to tell "reloaded because
// the navigation failed" from "reloaded with nothing mounted".
globalThis.__realError = console.error;
console.error = (...args) => { globalThis.__env.errors.push(args.map(String).join(' ')); };

// The interceptor narrows a[href] down to HTMLAnchorElement, so the double has
// to have one. A real browser always does; the global is what the scenario
// objects below are grafted onto (or deliberately not grafted onto, for the SVG
// case).
globalThis.HTMLAnchorElement = class HTMLAnchorElement {};
JS;
    }

    /**
     * The scenarios. Prints one JSON document on stdout and nothing else.
     */
    private static function harness(): string
    {
        return <<<'JS'
// Importing the asset runs the IIFE, which registers the click interceptor.
await import('./component.mjs');

const clickHandlers = globalThis.__listeners.document.click || [];
if (clickHandlers.length !== 1) {
  console.log(JSON.stringify({ results: [{
    name: 'harness sanity',
    pass: false,
    extra: `expected exactly one document click listener, found ${clickHandlers.length}`
  }] }));
} else {
  const handler = clickHandlers[0];

  // A stand-in for an HTMLAnchorElement: grafted onto the HTMLAnchorElement
  // prototype so `instanceof` agrees with a browser. `svg: true` models the
  // element `closest('a[href]')` really can return — something that matches the
  // selector but is not an HTML anchor (an SVGElement, which has neither a
  // relList nor a target property).
  const anchor = (attrs = {}, { svg = false } = {}) => {
    const el = {
      _attrs: attrs,
      hasAttribute: (n) => Object.prototype.hasOwnProperty.call(attrs, n),
      getAttribute: (n) => (Object.prototype.hasOwnProperty.call(attrs, n) ? attrs[n] : null),
      get target() { return Object.prototype.hasOwnProperty.call(attrs, 'target') ? attrs.target : ''; }
    };

    if (svg) {
      // Deliberately no relList and no target property, exactly like SVGElement.
      return el;
    }

    el.relList = { contains: (tok) => String(attrs.rel || '').split(/\s+/).includes(tok) };
    Object.setPrototypeOf(el, globalThis.HTMLAnchorElement.prototype);
    return el;
  };

  const run = async (a, eventOverrides = {}) => {
    globalThis.__fetchCalls = [];
    const event = {
      button: 0, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false,
      prevented: false,
      preventDefault() { this.prevented = true; },
      ...eventOverrides,
      target: { closest: () => a }
    };
    try {
      await handler(event);
      return { prevented: event.prevented, fetched: globalThis.__fetchCalls.length > 0 };
    } catch (err) {
      return { threw: String(err && err.message || err) };
    }
  };

  const results = [];
  const scenario = async (name, a, expect, overrides) => {
    const r = await run(a, overrides);
    results.push({
      name,
      pass: r.prevented === expect.prevented && r.fetched === expect.fetched && !r.threw,
      extra: JSON.stringify(r)
    });
  };

  const hijacked = { prevented: true, fetched: true };
  const browser = { prevented: false, fetched: false };

  await scenario('internal link is hijacked', anchor({ href: '/products' }), hijacked);
  await scenario('target=_self is hijacked', anchor({ href: '/products', target: '_self' }), hijacked);
  await scenario('target=_blank is left to the browser', anchor({ href: '/products', target: '_blank' }), browser);
  await scenario('target=_top is left to the browser', anchor({ href: '/products', target: '_top' }), browser);
  await scenario('download is left to the browser', anchor({ href: '/f.pdf', download: '' }), browser);
  await scenario('rel=external is left to the browser', anchor({ href: '/products', rel: 'noopener external' }), browser);
  await scenario('data-no-spa is left to the browser', anchor({ href: '/products', 'data-no-spa': '' }), browser);
  await scenario('mailto: is left to the browser', anchor({ href: 'mailto:hi@shop.test' }), browser);
  await scenario('a fragment is left to the browser', anchor({ href: '#totals' }), browser);
  await scenario('cross-origin is left to the browser', anchor({ href: 'https://other.test/x' }), browser);
  await scenario('a ctrl-click is left to the browser', anchor({ href: '/products' }), browser, { ctrlKey: true });
  await scenario('a middle-click is left to the browser', anchor({ href: '/products' }), browser, { button: 1 });
  await scenario('a malformed same-origin path is still hijacked', anchor({ href: 'ht tp://%' }), hijacked);
  await scenario('an unparseable href falls back to the browser', anchor({ href: 'http://' }), browser);

  // The hardening case: `a[href]` matches an SVG anchor too, and SVGElement has
  // no relList. A throw here is a rejected promise inside an async listener —
  // invisible, and it kills navigation for every later click on the page.
  await scenario(
    'an SVG anchor neither throws nor navigates',
    anchor({ href: '/products' }, { svg: true }),
    browser
  );

  // ── phase 2: a full, successful navigation ────────────────────────────────
  // Everything above answers "did the handler take the click?". These answer
  // "did the navigation it started survive its own post-mount steps?", which is
  // a different question with a much worse failure mode: navigate() wraps
  // everything in one try/catch whose catch is `window.location.href = url`, so
  // a throw in a *cosmetic* step (marking the active nav item) discards a page
  // that already mounted correctly and hard-reloads the browser instead.
  const navItem = (href) => ({
    _attrs: { href },
    getAttribute(n) { return this._attrs[n] ?? null; },
    // A DOMTokenList has toggle/remove/add; the interceptor uses all of them.
    classList: {
      toggle: (cls, on) => globalThis.__navOps.push({ href, cls, on }),
      remove: (cls) => globalThis.__navOps.push({ href, clsRemoved: cls }),
      add: (cls) => globalThis.__navOps.push({ href, clsAdded: cls })
    },
    setAttribute(n, v) { globalThis.__navOps.push({ href, attr: `${n}=${v}` }); },
    removeAttribute(n) { globalThis.__navOps.push({ href, attrRemoved: n }); }
  });

  const navigateTo = async (href, nav) => {
    globalThis.__env.fetchOk = true;
    globalThis.__env.nav = nav;
    globalThis.__env.errors = [];
    globalThis.__navOps = [];
    globalThis.__app._html = null;
    globalThis.__fetchCalls = [];
    // Phase 1 ends with ok:false, whose hard-fallback branch assigns
    // location.href — so the baseline has to be re-established here or the nav
    // sync below compares against the URL the last *failed* navigation left.
    globalThis.window.location.href = 'https://shop.test/orders';
    const before = globalThis.window.location.href;

    await run(anchor({ href }));

    return {
      mountedHtml: globalThis.__app._html,
      hardReloaded: globalThis.window.location.href !== before,
      locationNow: globalThis.window.location.href,
      errors: globalThis.__env.errors,
      navOps: globalThis.__navOps,
      // leave the environment as phase 1 expects it
      ...(() => { globalThis.__env.fetchOk = false; globalThis.__env.nav = []; return {}; })()
    };
  };

  const navCase = async (name, nav, expect) => {
    const r = await navigateTo('/products', nav);
    const failures = [];
    if (r.mountedHtml === null) failures.push('the page never mounted');
    if (r.hardReloaded) failures.push(`hard-reloaded to ${r.locationNow}`);
    if (r.errors.length) failures.push(`console.error: ${r.errors.join(' | ')}`);
    // expect() maps a failure message to "did this failure happen?".
    for (const [label, happened] of Object.entries(expect(r))) {
      if (happened) failures.push(label);
    }

    results.push({ name, pass: failures.length === 0, extra: failures.length ? failures.join('; ') : JSON.stringify(r) });
  };

  await navCase(
    'a well-formed nav still syncs and the navigation survives',
    [navItem('/orders'), navItem('/products')],
    // Keys are failure messages; the value must be "did this failure happen?".
    (r) => ({
      'the matching nav item was not marked active':
        !r.navOps.some((op) => op.href === '/products' && op.cls === 'active' && op.on === true),
      'aria-current was never set': !r.navOps.some((op) => op.attr === 'aria-current=page')
    })
  );

  await navCase(
    'one unparseable nav href cannot take the navigation down',
    [navItem('/orders'), navItem('/products'), navItem('http://')],
    (r) => ({
      'the other nav items were not synced':
        !r.navOps.some((op) => op.href === '/products' && op.cls === 'active' && op.on === true)
    })
  );

  // A nav item that is not same-origin HTTP has no pathname to compare, so it
  // must never end up marked active. Pinned as "not active" rather than as a
  // specific classList call, so it does not over-constrain how the handler
  // achieves it (removing the class, skipping the item, filtering it earlier).
  await navCase(
    'a non-http nav item is never marked active',
    [navItem('/products'), navItem('mailto:hi@shop.test'), navItem('tel:+30123456789')],
    (r) => ({
      'a mailto: nav item was marked active':
        r.navOps.some((op) => op.href.startsWith('mailto:') && op.cls === 'active' && op.on === true),
      'a tel: nav item was marked active':
        r.navOps.some((op) => op.href.startsWith('tel:') && op.cls === 'active' && op.on === true),
      'aria-current was given to a non-http nav item':
        r.navOps.some((op) => !op.href.startsWith('/') && op.attr === 'aria-current=page')
    })
  );

  console.log(JSON.stringify({ results }));
}
JS;
    }
}