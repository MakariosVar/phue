<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

/**
 * PhRichTextHarness — the executable scenarios behind PhRichTextTest.
 *
 * Everything here drives the real compiled module. The sanitizer and the
 * Markdown converter are module-level functions reached through the component's
 * own methods (`sanitize()`, `toMarkdown()`, `fromMarkdown()`), which are the
 * same functions the editor itself calls — so a scenario that passes proves the
 * shipped path is safe, not that a copy of it is.
 *
 * @package Phue\Tests\Core\UI
 */
final class PhRichTextHarness
{
    public static function source(): string
    {
        return <<<'JS'
const clean = (html) => globalThis.mountPhui({}).sanitize(html);
const toMd = (html) => globalThis.mountPhui({}).toMarkdown(html);
const fromMd = (md) => globalThis.mountPhui({}).fromMarkdown(md);

// ============================================================ script payloads

scenario('script_is_dropped_with_its_text', () => {
  assertEqual(clean('<p>before</p><script>alert(1)</script><p>after</p>'), '<p>before</p><p>after</p>');
  assertEqual(clean('<script>alert(1)</script>'), '');
  assertOmits(clean('<script>alert(1)</script>'), 'alert');
});

scenario('unclosed_script_does_not_swallow_the_document', () => {
  // The classic tokenizer bug: an unterminated raw-text element makes everything
  // after it "content", so the rest of the document disappears — or, in the other
  // direction, gets re-emitted as text.
  const out = clean('<p>kept</p><script>alert(1)');
  assertContains(out, '<p>kept</p>');
  assertOmits(out, 'alert');
});

scenario('svg_script_is_dropped', () => {
  assertEqual(clean('<svg><script>alert(1)</script></svg>'), '');
  assertEqual(clean('<p>a</p><svg onload="x()"><circle /></svg><p>b</p>'), '<p>a</p><p>b</p>');
});

// ======================================================= attribute payloads

scenario('onerror_attribute_is_dropped_with_the_img', () => {
  const out = clean('<img src=x onerror="alert(1)">');
  assertOmits(out, 'onerror');
  assertOmits(out, 'alert');
  assertOmits(out, 'src=x');
});

scenario('onclick_attribute_is_dropped_but_the_text_survives', () => {
  // Unwrap, not drop: the paragraph is allowed, so it stays; only the attribute
  // is stripped. Dropping the whole element would delete the user's words.
  assertEqual(clean('<p onclick="evil()">text</p>'), '<p>text</p>');
  assertEqual(clean('<div onmouseover="x">text</div>'), '<div>text</div>');
});

scenario('style_attribute_is_dropped', () => {
  // A style attribute is a positioning and an exfiltration channel, and the
  // editor's own output would not survive one either.
  assertEqual(clean('<p style="position:fixed;left:0;top:0">x</p>'), '<p>x</p>');
  assertOmits(clean('<h2 style="x">T</h2>'), 'style=');
});

// ============================================================= URL payloads

scenario('javascript_scheme_is_refused', () => {
  const out = clean('<a href="javascript:alert(1)">click</a>');
  assertOmits(out, 'javascript:');
  assertContains(out, 'click');
});

scenario('whitespace_obfuscated_javascript_scheme_is_refused', () => {
  // Browsers strip tab/newline/CR from a URL before resolving it, so
  // "java&#9;script:" navigates. A check on the raw string passes it.
  assertOmits(clean('<a href="java\tscript:alert(1)">x</a>'), 'script:');
  assertOmits(clean('<a href="java\nscript:alert(1)">x</a>'), 'script:');
  assertOmits(clean('<a href="  javascript:alert(1)">x</a>'), 'javascript');
  assertOmits(clean('<a href="JaVaScRiPt:alert(1)">x</a>'), 'alert');
  assertOmits(clean('<a href="vbscript:msgbox(1)">x</a>'), 'vbscript');
});

scenario('data_url_is_refused', () => {
  // The `>` inside the quoted value is the point: a tokenizer that stops at the
  // first `>` cuts this tag in half and the rest becomes visible text.
  const out = clean('<a href="data:text/html,<script>alert(1)</script>">x</a>');
  assertOmits(out, 'data:');
  assertOmits(out, 'alert');
});

scenario('safe_schemes_survive', () => {
  assertContains(clean('<a href="https://x.test/a">y</a>'), 'href="https://x.test/a"');
  assertContains(clean('<a href="http://x.test">y</a>'), 'http://x.test');
  assertContains(clean('<a href="mailto:a@b.test">y</a>'), 'mailto:a@b.test');
  assertContains(clean('<a href="tel:+306900000000">y</a>'), 'tel:');
  assertContains(clean('<a href="/relative/path">y</a>'), '/relative/path');
  assertContains(clean('<a href="#anchor">y</a>'), '#anchor');
  assertContains(clean('<a href="?page=2">y</a>'), '?page=2');
});

scenario('target_blank_gets_noopener_and_noreferrer', () => {
  // Forced by the sanitizer, not suggested to the author: without noopener the
  // opened page holds a live window.opener on ours.
  const out = clean('<a href="https://x.test" target="_blank">y</a>');
  assertContains(out, 'rel="noopener noreferrer"');
  assertContains(out, 'target="_blank"');
});

// ====================================================== structural round trip

scenario('allowed_markup_survives_verbatim', () => {
  const out = clean('<p>keep <strong>bold</strong> &amp; <em>it</em></p>');
  assertEqual(out, '<p>keep <strong>bold</strong> &amp; <em>it</em></p>');
  assertContains(clean('<ul><li>a</li><li>b</li></ul>'), '<ul><li>a</li><li>b</li></ul>');
  assertContains(clean('<h2>Title</h2>'), '<h2>Title</h2>');
  assertContains(clean('<blockquote><p>q</p></blockquote>'), '<blockquote><p>q</p></blockquote>');
  assertContains(clean('<pre><code>x</code></pre>'), '<pre><code>x</code></pre>');
  assertContains(clean('<p>a<br>b</p>'), '<br>');
  assertContains(clean('<hr>'), '<hr>');
});

scenario('unclosed_inline_tag_is_closed', () => {
  assertEqual(clean('<p>unclosed <b>bold'), '<p>unclosed <strong>bold</strong></p>');
});

scenario('crossed_tags_do_not_unbalance_the_tree', () => {
  // <b>a</i></b> must not unwind the stack past the element it is closing.
  const out = clean('<b>one<i>two</b>three</i>');
  assertContains(out, '<strong>');
  assertContains(out, '<em>');
  assertContains(out, 'one');
  assertContains(out, 'two');
  assertContains(out, 'three');
});

scenario('quoted_gt_inside_an_attribute_does_not_cut_the_tag', () => {
  const out = clean('<a title="a > b">t</a>');
  assertContains(out, 'title="a &gt; b"');
  assertContains(out, '>t</a>');
});

scenario('entities_are_not_double_escaped', () => {
  // The value passes through escape() on every emit and on the vdom write-back;
  // a second pass turning &amp; into &amp;amp; would show up as literal "&amp;".
  assertEqual(clean('<p>&amp;lt;</p>'), '<p>&amp;lt;</p>');
  assertEqual(clean('<p>a &amp; b</p>'), '<p>a &amp; b</p>');
  assertEqual(clean('<p>&nbsp;x</p>'), '<p>&nbsp;x</p>');
});

scenario('bare_ampersand_and_angle_brackets_are_escaped', () => {
  assertEqual(clean('<p>a & b < c > d</p>'), '<p>a &amp; b &lt; c &gt; d</p>');
});

scenario('comments_are_dropped', () => {
  assertEqual(clean('<!-- <script>alert(1)</script> -->visible'), 'visible');
});

scenario('scripty_form_controls_are_dropped', () => {
  const out = clean('<form action="/x"><input name="a"><button>send</button></form>');
  assertOmits(out, '<form');
  assertOmits(out, '<input');
  assertOmits(out, '<button');
});

// =========================================================== HTML -> Markdown

scenario('markdown_headings', () => {
  assertEqual(toMd('<h1>T</h1>'), '# T');
  assertEqual(toMd('<h2>T</h2><p>a</p>'), '## T\n\na');
  assertEqual(toMd('<h3>T</h3>'), '### T');
});

scenario('markdown_inline_styles', () => {
  assertEqual(toMd('<p>plain</p>'), 'plain');
  assertEqual(toMd('<p>a <strong>b</strong> c</p>'), 'a **b** c');
  assertEqual(toMd('<p>a <em>b</em> c</p>'), 'a _b_ c');
  assertEqual(toMd('<p>a <s>b</s> c</p>'), 'a ~~b~~ c');
  assertEqual(toMd('<p>use <code>x</code> here</p>'), 'use `x` here');
});

scenario('markdown_lists', () => {
  assertEqual(toMd('<ul><li>one</li><li>two</li></ul>'), '- one\n- two');
  assertEqual(toMd('<ol><li>a</li><li>b</li></ol>'), '1. a\n2. b');
  assertEqual(toMd('<ul><li>a</li><li><ul><li>b</li></ul></li></ul>'), '- a\n  - b');
});

scenario('markdown_link_quote_rule_and_hr', () => {
  assertEqual(toMd('<a href="https://x.test">link</a>'), '[link](https://x.test)');
  assertEqual(toMd('<blockquote><p>q</p></blockquote>'), '> q');
  assertEqual(toMd('<hr>'), '---');
  assertEqual(toMd('<pre><code>let a = 1;</code></pre>'), '```\nlet a = 1;\n```');
  // A URL with a space breaks the link syntax unless it is bracketed.
  assertEqual(toMd('<a href="https://x.test/a b">y</a>'), '[y](<https://x.test/a b>)');
});

scenario('markdown_escapes_markdown_punctuation_in_text', () => {
  // The whole point of an escape: prose that LOOKS like syntax must come back out
  // of the parser as the same characters, not as formatting.
  assertEqual(toMd('<p>2 * 3 = 6</p>'), '2 \\* 3 = 6');
  assertEqual(toMd('<p>snake_case_name</p>'), 'snake\\_case\\_name');
  assertContains(toMd('<p>[not a link]</p>'), '\\[not a link\\]');
  assertEqual(toMd('<p># not a heading</p>'), '\\# not a heading');
});

scenario('markdown_of_an_empty_document_is_empty', () => {
  assertEqual(toMd(''), '');
  assertEqual(toMd('<p></p>'), '');
  assertEqual(toMd('<br>'), '');
});

scenario('markdown_drops_underline', () => {
  assertEqual(toMd('<p>a <u>b</u> c</p>'), 'a b c');
});

// =========================================================== Markdown -> HTML

scenario('markdown_parser_headings_and_paragraphs', () => {
  assertEqual(fromMd('# T'), '<h1>T</h1>');
  assertEqual(fromMd('### T'), '<h3>T</h3>');
  assertEqual(fromMd('one\n\ntwo'), '<p>one</p>\n<p>two</p>');
  assertContains(fromMd('**b** and _i_ and ~~s~~'), '<strong>b</strong>');
  assertContains(fromMd('**b** and _i_ and ~~s~~'), '<em>i</em>');
  assertContains(fromMd('**b** and _i_ and ~~s~~'), '<s>s</s>');
  assertContains(fromMd('a `b` c'), '<code>b</code>');
  assertContains(fromMd('line one  \nline two'), '<br>');
  assertEqual(fromMd('---'), '<hr>');
});

scenario('markdown_parser_lists', () => {
  assertEqual(fromMd('- a\n- b'), '<ul><li><p>a</p></li><li><p>b</p></li></ul>');
  assertEqual(fromMd('1. a\n2. b'), '<ol><li><p>a</p></li><li><p>b</p></li></ol>');
});

scenario('markdown_parser_quote_and_fence', () => {
  assertEqual(fromMd('> q'), '<blockquote><p>q</p></blockquote>');
  assertContains(fromMd('```\nlet a = 1;\n```'), '<pre><code>let a = 1;');
});

scenario('markdown_parser_neutralises_raw_html', () => {
  // Neutralised, not deleted: the reader still SEES the tag they typed, as text.
  // What must not happen is a live element.
  const out = fromMd('Hello <script>alert(1)</script> world');
  assertOmits(out, '<script');
  assertContains(out, '&lt;script&gt;');
  assertContains(out, 'Hello');

  // Exact, because the payload is expected to survive as INERT TEXT: the words
  // `onerror` and `src=x` are visible to the reader, and asserting they are
  // absent would push someone into deleting the user's text to satisfy a test.
  // What must not exist is the element.
  const img = fromMd('<img src=x onerror=alert(1)>');
  assertEqual(img, '<p>&lt;img src=x onerror=alert(1)&gt;</p>');
});

scenario('markdown_parser_refuses_a_javascript_link', () => {
  const out = fromMd('[x](javascript:alert(1))');
  assertOmits(out, 'javascript:');
});

scenario('markdown_round_trip_is_stable', () => {
  // md -> html -> md has to be a fixed point, or every save rewrites the document
  // and a diff is unreadable.
  const source = [
    '# Title',
    '',
    'A paragraph with **bold**, _italic_, ~~struck~~ and `code`.',
    '',
    '- one',
    '- two',
    '',
    '1. first',
    '2. second',
    '',
    '> a quote',
    '',
    '[a link](https://example.test/page)',
    '',
    '---',
  ].join('\n');

  const once = toMd(fromMd(source));
  const twice = toMd(fromMd(once));
  assertEqual(twice, once);
  assertContains(once, '# Title');
  assertContains(once, '- one');
  assertContains(once, '> a quote');
  assertContains(once, '[a link](https://example.test/page)');
});

// =================================================================== component

scenario('markdown_value_is_parsed_into_the_editor', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: '# Hi', mode: 'markdown' } });
  assertEqual(vm.html, '<h1>Hi</h1>');
});

scenario('html_value_is_sanitised_into_the_editor', () => {
  // The value reaches the document through `v-html`, so the mirror the component
  // renders is the sanitised one — never the prop.
  const vm = globalThis.mountPhui({ props: { modelValue: '<p>a</p><script>alert(1)</script>' } });
  assertEqual(vm.html, '<p>a</p>');
  assertOmits(vm.html, 'script');
});

scenario('onInput_emits_sanitised_html', () => {
  const editor = { innerHTML: '<p>keep</p><script>alert(1)</script><b>bold</b>' };
  const vm = globalThis.mountPhui({ props: { modelValue: '' }, refs: { editor } });

  vm.onInput();

  const emitted = vm.__emitted.filter((row) => row.event === 'update:modelValue');
  assertEqual(emitted.length, 1);
  assertEqual(emitted[0].args[0], '<p>keep</p><strong>bold</strong>');
});

scenario('onInput_emits_markdown_in_markdown_mode', () => {
  const editor = { innerHTML: '<h2>Title</h2><ul><li>a</li></ul>' };
  const vm = globalThis.mountPhui({ props: { modelValue: '', mode: 'markdown' }, refs: { editor } });

  vm.onInput();

  const emitted = vm.__emitted.filter((row) => row.event === 'update:modelValue');
  assertEqual(emitted[0].args[0], '## Title\n\n- a');
});

scenario('onInput_leaves_the_vdom_mirror_alone', () => {
  // Writing `html` back here would re-patch innerHTML on every keystroke and move
  // the caret to the start of the document. The DOM is already correct.
  const editor = { innerHTML: '<p>typed</p>' };
  const vm = globalThis.mountPhui({ props: { modelValue: '' }, refs: { editor } });

  const before = vm.html;
  vm.onInput();
  assertEqual(vm.html, before);
});

scenario('setMode_to_markdown_converts_the_editor_content', () => {
  // Not the prop: the prop is still the HTML value, and reinterpreting it as
  // Markdown would render "# Title" as literal text.
  const editor = { innerHTML: '<h2>Title</h2><p>Body</p>' };
  const vm = globalThis.mountPhui({ props: { modelValue: '<h2>Title</h2><p>Body</p>' }, refs: { editor } });

  vm.setMode('markdown');

  assertEqual(vm.currentMode, 'markdown');
  const emitted = vm.__emitted.filter((row) => row.event === 'update:modelValue');
  assertEqual(emitted[emitted.length - 1].args[0], '## Title\n\nBody');
  // The editor surface is the PARSED value, so what the reader sees and what will
  // be parsed back are the same document.
  assertEqual(vm.html, '<h2>Title</h2>\n<p>Body</p>');
  assertTrue(vm.__emitted.some((row) => row.event === 'mode-change' && row.args[0] === 'markdown'));
});

scenario('setMode_is_a_no_op_for_the_current_mode', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: 'a' } });
  vm.setMode('html');
  assertEqual(vm.__emitted.length, 0);
});

scenario('setMode_rejects_an_unknown_mode', () => {
  const vm = globalThis.mountPhui({ props: { modelValue: 'a' } });
  vm.setMode('rtf');
  assertEqual(vm.currentMode, 'html');
  assertEqual(vm.__emitted.length, 0);
});

scenario('paste_refuses_to_insert_raw_markup', () => {
  // The DOM handler has to stop the browser from inserting the payload: an
  // `<img onerror>` fires the moment it lands, long before anything reads the
  // value. Sanitising the emitted value afterwards cannot help.
  const source = globalThis.__PHUE_SOURCE__;
  assertContains(source, 'event.preventDefault()');
  assertContains(source, "getData('text/html')");
  assertContains(source, 'sanitizeHtml(html)');

  const editor = { innerHTML: '<p>a</p>' };
  const vm = globalThis.mountPhui({ props: { modelValue: '' }, refs: { editor } });

  let prevented = false;
  vm.onPaste({
    clipboardData: {
      getData: (kind) => (kind === 'text/html' ? '<p>x</p><img src=q onerror=alert(1)>' : ''),
    },
    preventDefault: () => { prevented = true; },
  });

  assertTrue(prevented);
});
JS;
    }

    /**
     * The component's own source, handed to the harness so a scenario can assert
     * on the call site it is describing rather than restating it.
     */
    public static function sourceWith(string $componentSource): string
    {
        return 'globalThis.__PHUE_SOURCE__ = ' . json_encode($componentSource) . ";\n"
            . self::source()
            . "\nglobalThis.__emitResults();\n";
    }
}