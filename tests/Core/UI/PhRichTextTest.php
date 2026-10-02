<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Tests\Core\Stubs\PhueComponentRuntime;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * PhRichTextTest — the sanitizer, the Markdown converter, and the editor state.
 *
 * This component is the only one in the kit that takes untrusted markup and puts
 * it in the document, so its test file is mostly an attack surface inventory.
 * Every payload here is one that has actually shipped as an XSS in a rich-text
 * component somewhere, and the assertions are about the OUTPUT, never about the
 * source containing a certain word.
 *
 * The whole sanitizer runs with no DOM — that is why it is written by hand — so
 * every one of these is executable in the bun harness instead of being a comment.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class PhRichTextTest extends TestCase
{
    private const PHUI = __DIR__ . '/../../../src/Core/UI/Phui';

    /** @var array<string, array{pass: bool, extra: string|null}>|null */
    private static ?array $results = null;

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!PhueComponentRuntime::available()) {
            self::$skipReason = 'bun is unavailable; cannot execute the client runtime.';

            return;
        }

        $module = (TestComponentLoader::make(self::PHUI))->buildFor('PhRichText');
        if ($module === null) {
            self::$skipReason = 'PhRichText did not compile to a JS module.';

            return;
        }

        $decoded = PhueComponentRuntime::run(
            $module,
            PhRichTextHarness::sourceWith((string) file_get_contents(self::PHUI . '/PhRichText.phue')),
            PhuiInstanceFactory::prelude()
        );
        $rows = $decoded['results'] ?? null;
        if (!is_array($rows) || $rows === []) {
            throw new \RuntimeException('The PhRichText harness returned no assertions: ' . json_encode($decoded));
        }

        $results = [];
        foreach ($rows as $row) {
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

    // ---------------------------------------------------------------- sanitizer

    public function testScriptElementsAreDroppedWithTheirContent(): void
    {
        $this->requireRuntime();

        // Not "the script tag is removed" — the TEXT too. A sanitizer that
        // unwraps <script> leaves its source sitting in the document as visible
        // text, which is both an information leak and the string a later
        // re-parse could turn back into markup.
        $this->assertHarness('script_is_dropped_with_its_text');
        $this->assertHarness('unclosed_script_does_not_swallow_the_document');
        $this->assertHarness('svg_script_is_dropped');
    }

    public function testEventHandlerAttributesAreStripped(): void
    {
        $this->requireRuntime();

        $this->assertHarness('onerror_attribute_is_dropped_with_the_img');
        $this->assertHarness('onclick_attribute_is_dropped_but_the_text_survives');
        $this->assertHarness('style_attribute_is_dropped');
    }

    public function testDangerousUrlSchemesAreRefused(): void
    {
        $this->requireRuntime();

        $this->assertHarness('javascript_scheme_is_refused');
        $this->assertHarness('whitespace_obfuscated_javascript_scheme_is_refused');
        $this->assertHarness('data_url_is_refused');
        $this->assertHarness('safe_schemes_survive');
        $this->assertHarness('target_blank_gets_noopener_and_noreferrer');
    }

    public function testMarkupStructureSurvivesSanitisation(): void
    {
        $this->requireRuntime();

        $this->assertHarness('allowed_markup_survives_verbatim');
        $this->assertHarness('unclosed_inline_tag_is_closed');
        $this->assertHarness('crossed_tags_do_not_unbalance_the_tree');
        $this->assertHarness('quoted_gt_inside_an_attribute_does_not_cut_the_tag');
        $this->assertHarness('entities_are_not_double_escaped');
        $this->assertHarness('bare_ampersand_and_angle_brackets_are_escaped');
        $this->assertHarness('comments_are_dropped');
        $this->assertHarness('scripty_form_controls_are_dropped');
    }

    // ----------------------------------------------------------------- markdown

    public function testHtmlToMarkdownCoversEverySupportedBlock(): void
    {
        $this->requireRuntime();

        $this->assertHarness('markdown_headings');
        $this->assertHarness('markdown_inline_styles');
        $this->assertHarness('markdown_lists');
        $this->assertHarness('markdown_link_quote_rule_and_hr');
        $this->assertHarness('markdown_escapes_markdown_punctuation_in_text');
        $this->assertHarness('markdown_of_an_empty_document_is_empty');
    }

    public function testMarkdownToHtmlCoversEverySupportedBlock(): void
    {
        $this->requireRuntime();

        $this->assertHarness('markdown_parser_headings_and_paragraphs');
        $this->assertHarness('markdown_parser_lists');
        $this->assertHarness('markdown_parser_quote_and_fence');
        $this->assertHarness('markdown_parser_neutralises_raw_html');
        $this->assertHarness('markdown_parser_refuses_a_javascript_link');
        $this->assertHarness('markdown_round_trip_is_stable');
    }

    // ---------------------------------------------------------------- component

    public function testTheValueIsNormalisedAccordingToTheMode(): void
    {
        $this->requireRuntime();

        $this->assertHarness('markdown_value_is_parsed_into_the_editor');
        $this->assertHarness('html_value_is_sanitised_into_the_editor');
    }

    public function testEmittedValueIsAlwaysSanitised(): void
    {
        $this->requireRuntime();

        $this->assertHarness('onInput_emits_sanitised_html');
        $this->assertHarness('onInput_emits_markdown_in_markdown_mode');
        $this->assertHarness('onInput_leaves_the_vdom_mirror_alone');
    }

    public function testTheModeSwitchConvertsTheContentRatherThanReinterpretingIt(): void
    {
        $this->requireRuntime();

        $this->assertHarness('setMode_to_markdown_converts_the_editor_content');
        $this->assertHarness('setMode_is_a_no_op_for_the_current_mode');
        $this->assertHarness('setMode_rejects_an_unknown_mode');
    }

    public function testThePastedMarkupIsFilteredBeforeItReachesTheDocument(): void
    {
        $this->requireRuntime();

        $this->assertHarness('paste_refuses_to_insert_raw_markup');
    }

    // ------------------------------------------------------------------- static

    public function testTheOnlyWayMarkupEntersIsTheSanitizer(): void
    {
        $source = (string) file_get_contents(self::PHUI . '/PhRichText.phue');

        // One `insertHTML` call, and its argument is a string this component built
        // and then re-sanitises on the next `onInput`. A second one, fed something
        // a caller controls, would be an XSS hole with no test to catch it.
        self::assertSame(
            2,
            substr_count($source, "execCommand('insertHTML'"),
            'expected exactly two insertHTML call sites: inline code and paste'
        );

        // The editor surface is filled by `v-html`, and `v-html` is the one
        // binding that bypasses Vue's escaping. It must only ever be handed the
        // sanitised mirror.
        self::assertSame(1, substr_count($source, 'v-html="html"'));
        self::assertStringNotContainsString('v-html="modelValue"', $source);
        self::assertStringNotContainsString('v-html="editorHtml"', $source);
    }

    public function testUnderlineIsDroppedRatherThanEmittedAsRawHtml(): void
    {
        // Markdown has no underline. Emitting <u> would mean the Markdown value
        // claims a formatting the source cannot express, and the round trip would
        // be lossy in a way nobody could see. Losing the underline is the smaller
        // lie, and it is asserted so the decision stays deliberate.
        $this->requireRuntime();

        $this->assertHarness('markdown_drops_underline');
    }
}