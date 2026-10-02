<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Tests\Core\Stubs\PhueComponentRuntime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * PhCarouselTest — the slide state of <ph-carousel>.
 *
 * This is the framework's only component with no Bootstrap plugin behind it.
 * Bootstrap's carousel is 100% JavaScript, so there is no "just add the data
 * attribute" path — every behaviour below is written here and has to hold on its
 * own.
 *
 * The carousel is also the single worst accessibility offender the kit could ship,
 * so the tests are not only about the state machine: an auto-rotating carousel
 * that never stops is a screen reader announcing new content forever and a
 * keyboard user unable to read anything.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('integration')]
final class PhCarouselTest extends TestCase
{
    private const PHUI = __DIR__ . '/../../../src/Core/UI/Phui';

    /** @var array<string, array{pass: bool, extra: string|null}>|null */
    private static ?array $results = null;

    private static string $skipReason = '';

    private const SLIDES = [
        ['src' => '/a.jpg', 'alt' => 'A', 'caption' => 'First'],
        ['src' => '/b.jpg', 'alt' => 'B', 'caption' => 'Second'],
        ['src' => '/c.jpg', 'alt' => 'C', 'caption' => 'Third'],
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!PhueComponentRuntime::available()) {
            self::$skipReason = 'bun is unavailable; cannot execute the client runtime.';

            return;
        }

        $module = (TestComponentLoader::make(self::PHUI))->buildFor('PhCarousel');
        if ($module === null) {
            self::$skipReason = 'PhCarousel did not compile to a JS module.';

            return;
        }

        $decoded = PhueComponentRuntime::run($module, self::scenarios(), PhuiInstanceFactory::prelude());
        $results = [];
        foreach (($decoded['results'] ?? []) as $row) {
            $results[(string) $row['name']] = ['pass' => (bool) $row['pass'], 'extra' => $row['extra'] ?? null];
        }
        self::$results = $results;
    }

    private function requireRuntime(): void
    {
        if (self::$results === null) {
            self::markTestSkipped(self::$skipReason);
        }
    }

    private function assertHarness(string $name): void
    {
        self::assertArrayHasKey($name, self::$results ?? [], "The harness never reported [{$name}].");
        $row = self::$results[$name];

        self::assertTrue($row['pass'], "Harness scenario [{$name}] failed: " . (string) $row['extra']);
    }

    public function testAnOutOfRangeIndexIsNormalisedRatherThanTrusted(): void
    {
        $this->requireRuntime();

        // A `modelValue` written by a parent is normalised too. Taking it at face
        // value translates the track past the last slide with no way back.
        $this->assertHarness('a_negative_index_wraps_to_the_end');
        $this->assertHarness('an_index_past_the_end_wraps_to_the_start');
        $this->assertHarness('a_non_numeric_index_degrades_to_the_first_slide');
        $this->assertHarness('an_empty_carousel_has_no_index_at_all');
    }

    public function testNavigationAnnouncesItself(): void
    {
        $this->requireRuntime();

        $this->assertHarness('moving_emits_update_and_change');
        $this->assertHarness('next_and_previous_emit_their_own_event');
    }

    public function testTheEndsBehaveAccordingToLoop(): void
    {
        $this->requireRuntime();

        $this->assertHarness('looping_wraps_past_the_last_slide');
        $this->assertHarness('looping_wraps_before_the_first_slide');
        $this->assertHarness('without_loop_the_ends_are_dead');
    }

    public function testAnEmptyCarouselIsInert(): void
    {
        $this->requireRuntime();

        $this->assertHarness('navigation_on_an_empty_carousel_does_nothing');
    }

    public function testAutoplayRespectsTheReader(): void
    {
        $this->requireRuntime();

        $this->assertHarness('reduced_motion_disables_the_timer_entirely');
        $this->assertHarness('a_hidden_tab_stops_the_timer');
        $this->assertHarness('hovering_pauses_the_timer');
    }

    public function testTouchAndLayout(): void
    {
        $this->requireRuntime();

        $this->assertHarness('a_short_touch_is_not_a_swipe');
        $this->assertHarness('a_swipe_left_advances_and_right_goes_back');
        $this->assertHarness('the_track_is_translated_by_one_frame_per_slide');
        $this->assertHarness('fade_keeps_the_active_slide_in_the_flow');
    }

    public function testTheMarkupIsAnAccessibleRegion(): void
    {
        $source = (string) file_get_contents(self::PHUI . '/PhCarousel.phue');

        // The controls are glyphs from Bootstrap's CSS paint, so their names have
        // to come from somewhere: two visually-hidden labels.
        self::assertStringContainsString('aria-roledescription="carousel"', $source);
        self::assertStringContainsString('aria-roledescription="slide"', $source);
        self::assertStringContainsString('>Previous slide</span>', $source);
        self::assertStringContainsString('>Next slide</span>', $source);

        // An auto-rotating region must not be a live region while it rotates, or
        // the screen reader interrupts itself on every tick.
        self::assertStringContainsString(":aria-live=\"playing ? 'off' : 'polite'\"", $source);
    }

    public function testBootstrapsSlidePluginClassesAreNotReused(): void
    {
        $source = (string) file_get_contents(self::PHUI . '/PhCarousel.phue');

        // `.carousel-item` is `display:none` unless `.active` and floats with a
        // `-100%` margin. It works only because bootstrap.js adds and removes
        // `.active`, which does not exist here: reusing it renders exactly one
        // slide, or none.
        self::assertStringNotContainsString('class="carousel-item', $source);
        self::assertStringNotContainsString('"carousel-item"', $source);
        self::assertStringNotContainsString('"carousel-fade"', $source);

        // The parts that ARE pure CSS paint stay.
        self::assertStringContainsString('carousel-indicators', $source);
        self::assertStringContainsString('carousel-control-prev', $source);

        // `data-bs-target` is what Bootstrap's indicator selector keys on, so it has
        // to name an element that exists. A placeholder value renders the bars fine
        // and the attribute lies about what it drives.
        self::assertStringContainsString(
            ':data-bs-target="\'#\' + carouselId"',
            $source,
            'the indicator bars must target an element that exists'
        );
        self::assertStringNotContainsString('phue-noop', $source);
    }

    private static function scenarios(): string
    {
        return <<<'JS'
const SLIDES = [
  { src: '/a.jpg', alt: 'A', caption: 'First' },
  { src: '/b.jpg', alt: 'B', caption: 'Second' },
  { src: '/c.jpg', alt: 'C', caption: 'Third' },
];
const mount = (props) => globalThis.mountPhui({ props: Object.assign({ slides: SLIDES }, props || {}) });

const touch = (x) => ({ changedTouches: [{ clientX: x }] });

scenario('a_negative_index_wraps_to_the_end', () => {
  assertEqual(mount({ modelValue: -1 }).index, 2);
  assertEqual(mount({ modelValue: -4 }).index, 2);
});

scenario('an_index_past_the_end_wraps_to_the_start', () => {
  assertEqual(mount({ modelValue: 5 }).index, 2);
  assertEqual(mount({ modelValue: 3 }).index, 0);
});

scenario('a_non_numeric_index_degrades_to_the_first_slide', () => {
  assertEqual(mount({ modelValue: 'two' }).index, 0);
  assertEqual(mount({ modelValue: NaN }).index, 0);
});

scenario('an_empty_carousel_has_no_index_at_all', () => {
  const vm = globalThis.mountPhui({ props: { slides: [] } });
  assertEqual(vm.index, 0);
  assertEqual(vm.slideLabel(0), 'Slide 1 of 0');
});

scenario('moving_emits_update_and_change', () => {
  const vm = mount({});
  vm.goTo(2, true);

  assertEqual(vm.index, 2);
  const events = vm.__emitted.map((row) => row.event);
  assertContains(events.join(','), 'update:modelValue');
  assertContains(events.join(','), 'change');
  assertEqual(vm.__emitted[0].args[0], 2);
});

scenario('moving_to_the_current_slide_is_silent', () => {
  const vm = mount({});
  vm.goTo(0, true);

  // Re-announcing the same slide makes a parent's @change fire spuriously.
  assertEqual(vm.__emitted.length, 0);
});

scenario('next_and_previous_emit_their_own_event', () => {
  const forward = mount({});
  forward.next();
  assertTrue(forward.__emitted.some((row) => row.event === 'next'));
  assertEqual(forward.index, 1);

  const backward = mount({ modelValue: 1 });
  backward.previous();
  assertTrue(backward.__emitted.some((row) => row.event === 'prev'));
  assertEqual(backward.index, 0);
});

scenario('looping_wraps_past_the_last_slide', () => {
  const vm = mount({ modelValue: 2 });
  vm.next();
  assertEqual(vm.index, 0);
});

scenario('looping_wraps_before_the_first_slide', () => {
  const vm = mount({ modelValue: 0 });
  vm.previous();
  assertEqual(vm.index, 2);
});

scenario('without_loop_the_ends_are_dead', () => {
  const last = mount({ modelValue: 2, loop: false });
  last.next();
  assertEqual(last.index, 2);
  assertEqual(last.__emitted.length, 0);

  const first = mount({ modelValue: 0, loop: false });
  first.previous();
  assertEqual(first.index, 0);
  assertEqual(first.__emitted.length, 0);
});

scenario('navigation_on_an_empty_carousel_does_nothing', () => {
  const vm = globalThis.mountPhui({ props: { slides: [] } });
  vm.next();
  vm.previous();
  vm.goTo(3, true);
  assertEqual(vm.__emitted.length, 0);
});

scenario('reduced_motion_disables_the_timer_entirely', () => {
  // Autoplay is motion the reader did not ask for. `matchMedia` is installed as a
  // double, so the scenario reads the branch the component really takes.
  globalThis.window = { matchMedia: (query) => ({ matches: query.includes('reduce') }) };

  const vm = mount({ interval: 1000 });
  vm.startTimer();

  assertEqual(vm.timer, null, 'no interval may be scheduled under prefers-reduced-motion');

  globalThis.window = undefined;
});

scenario('a_hidden_tab_stops_the_timer', () => {
  globalThis.window = { matchMedia: () => ({ matches: false }) };
  globalThis.document = { hidden: true, addEventListener: () => {}, removeEventListener: () => {} };

  const vm = mount({ interval: 1000 });
  vm.onVisibilityChange();

  assertTrue(vm.hidden);
  assertFalse(vm.playing, 'a carousel must not keep counting while the tab is in the background');

  globalThis.document = undefined;
  globalThis.window = undefined;
});

scenario('hovering_pauses_the_timer', () => {
  const vm = mount({ interval: 1000 });
  assertTrue(vm.playing);

  vm.pause();
  assertFalse(vm.playing);

  vm.resume();
  assertTrue(vm.playing);

  const off = mount({ interval: 1000, pauseOnHover: false });
  off.pause();
  assertTrue(off.playing);
});

scenario('a_short_touch_is_not_a_swipe', () => {
  const vm = mount({});
  vm.onTouchStart(touch(100));
  vm.onTouchEnd(touch(110));

  // Under the threshold it is a tap on the slide, and advancing would yank the
  // content out from under the finger.
  assertEqual(vm.index, 0);
});

scenario('a_swipe_left_advances_and_right_goes_back', () => {
  const forward = mount({});
  forward.onTouchStart(touch(200));
  forward.onTouchEnd(touch(100));
  assertEqual(forward.index, 1);

  const backward = mount({ modelValue: 1 });
  backward.onTouchStart(touch(100));
  backward.onTouchEnd(touch(200));
  assertEqual(backward.index, 0);
});

scenario('the_track_is_translated_by_one_frame_per_slide', () => {
  const vm = mount({ modelValue: 1 });
  assertContains(vm.trackStyle.transform, '-100%');
  assertContains(vm.trackStyle.transition, 'transform');
});

scenario('fade_keeps_the_active_slide_in_the_flow', () => {
  // The active slide is the one that gives the frame its height, and `relative` is
  // still in flow — so it both sizes the frame and anchors its own caption.
  const vm = mount({ modelValue: 1, fade: true });

  assertEqual(vm.slideStyle(1).position, 'relative');
  assertEqual(vm.slideStyle(0).position, 'absolute');
  assertEqual(vm.slideStyle(0).opacity, 0);
  assertEqual(vm.slideStyle(1).opacity, 1);
});

scenario('fade_does_not_translate_the_track', () => {
  const vm = mount({ modelValue: 1, fade: true });

  // The regression: a translated track carries the ACTIVE slide out of the frame,
  // leaving only the (transparent) absolutely-positioned ones behind — a blank
  // carousel on every slide after the first.
  assertEqual(vm.trackStyle.transform, 'none');
  assertEqual(vm.trackStyle.transition, 'none');
});

scenario('slide_mode_still_translates_the_track', () => {
  const vm = mount({ modelValue: 1 });
  assertContains(vm.trackStyle.transform, '-100%');
});

scenario('every_slide_is_a_positioning_context', () => {
  // `.carousel-caption` is absolutely positioned; without a positioned slide it
  // resolves against the root and every caption stacks in one corner.
  const sliding = mount({ modelValue: 1 });
  assertEqual(sliding.slideStyle(0).position, 'relative');
  assertEqual(sliding.slideStyle(2).position, 'relative');

  const fading = mount({ modelValue: 1, fade: true });
  assertEqual(fading.slideStyle(1).position, 'relative');
  assertEqual(fading.slideStyle(0).position, 'absolute');
});

scenario('the_track_fills_the_frame', () => {
  const vm = mount({});
  assertEqual(vm.trackStyle.height, '100%');
});

scenario('the_indicators_clear_the_caption', () => {
  const vm = mount({});
  assertContains(vm.indicatorStyle.marginBottom, '0');
  assertTrue(String(vm.indicatorStyle.bottom).length > 0);
});

scenario('slide_labels_are_announced_with_their_position', () => {
  const vm = mount({});
  assertEqual(vm.slideLabel(0), 'First (1 of 3)');

  const untitled = globalThis.mountPhui({ props: { slides: [{ src: '/a.jpg' }, { src: '/b.jpg' }] } });
  assertEqual(untitled.slideLabel(1), 'Slide 2 of 2');
});
JS . "\nglobalThis.__emitResults();\n";
    }
}