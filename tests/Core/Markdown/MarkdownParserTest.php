<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Markdown;

use Phue\Core\Utils\MarkdownParser;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the XSS-safe markdown parser.
 *
 * Covers every supported construct (headings, bold/italic, inline code,
 * fenced blocks, lists, links, blockquotes, paragraphs), edge cases
 * (unclosed fences, list termination, empty input), and the security
 * contract: content is escaped BEFORE any formatting transformation.
 *
 * @package Phue\Tests\Core\Markdown
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class MarkdownParserTest extends TestCase
{
    private MarkdownParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MarkdownParser();
    }

    // ------------------------------------------------------------- headings

    public function testParsesAllHeadingLevels(): void
    {
        $html = $this->parser->parse("# H1\n## H2\n### H3\n#### H4\n##### H5\n###### H6");

        $this->assertStringContainsString('<h1 id="h1">H1</h1>', $html);
        $this->assertStringContainsString('<h2 id="h2">H2</h2>', $html);
        $this->assertStringContainsString('<h3 id="h3">H3</h3>', $html);
        $this->assertStringContainsString('<h4 id="h4">H4</h4>', $html);
        $this->assertStringContainsString('<h5 id="h5">H5</h5>', $html);
        $this->assertStringContainsString('<h6 id="h6">H6</h6>', $html);
        $this->assertStringNotContainsString('<h7>', $html, '7+ # characters must not produce an h7 tag');
    }

    public function testHeadingsGetSlugAnchorIdsForDeepLinking(): void
    {
        $html = $this->parser->parse('## Getting Started & First Steps');

        $this->assertStringContainsString('<h2 id="getting-started-first-steps">', $html);
    }

    public function testHeadingWithInlineFormatting(): void
    {
        $html = $this->parser->parse('## Title with **bold** and `code`');

        $this->assertStringContainsString('<h2 id="title-with-bold-and-code">Title with <strong>bold</strong> and <code>code</code></h2>', $html);
    }

    public function testHeadingRequiresSpaceAfterHash(): void
    {
        $html = $this->parser->parse('#NoSpace');

        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringContainsString('#NoSpace', $html);
    }

    // ------------------------------------------------------------ paragraphs

    public function testParsesParagraphs(): void
    {
        $html = $this->parser->parse("First paragraph.\n\nSecond paragraph.");

        $this->assertStringContainsString('<p>First paragraph.</p>', $html);
        $this->assertStringContainsString('<p>Second paragraph.</p>', $html);
    }

    public function testConsecutiveLinesJoinIntoOneParagraph(): void
    {
        $html = $this->parser->parse("First line\nsecond line\nthird line.");

        $this->assertStringContainsString('<p>First line second line third line.</p>', $html);
        $this->assertStringNotContainsString('<p>First line</p>', $html);
    }

    public function testInlineFormattingMaySpanLinesInsideParagraph(): void
    {
        $html = $this->parser->parse("**no whitelist, no import; auto-discovered\nfrom the template and imported into one module.**");

        $this->assertStringContainsString(
            '<p><strong>no whitelist, no import; auto-discovered from the template and imported into one module.</strong></p>',
            $html
        );
    }

    public function testEmptyInputReturnsEmptyString(): void
    {
        $this->assertSame('', $this->parser->parse(''));
    }

    // ------------------------------------------------------- inline formatting

    public function testParsesBold(): void
    {
        $html = $this->parser->parse('This is **bold** and __also bold__.');

        $this->assertStringContainsString('This is <strong>bold</strong> and <strong>also bold</strong>.', $html);
    }

    public function testParsesItalic(): void
    {
        $html = $this->parser->parse('This is *italic* and _also italic_.');

        $this->assertStringContainsString('This is <em>italic</em> and <em>also italic</em>.', $html);
    }

    public function testParsesInlineCode(): void
    {
        $html = $this->parser->parse('Use `composer install` first.');

        $this->assertStringContainsString('Use <code>composer install</code> first.', $html);
    }

    public function testParsesLinks(): void
    {
        $html = $this->parser->parse('[Phue](https://example.com)');

        $this->assertStringContainsString('<a href="https://example.com">Phue</a>', $html);
    }

    // ----------------------------------------------------------- code blocks

    public function testParsesFencedCodeBlock(): void
    {
        $html = $this->parser->parse("```\n\$app->handle(\$request);\n```");

        $this->assertStringContainsString('<pre><code>', $html);
        $this->assertStringContainsString('$app-&gt;handle($request);', $html, 'Code content must be escaped');
        $this->assertStringContainsString('</code></pre>', $html);
    }

    public function testCodeBlockContentIsNotFormatted(): void
    {
        $html = $this->parser->parse("```\n**not bold** #not-heading\n```");

        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringContainsString('**not bold**', $html);
    }

    public function testUnclosedCodeBlockAutoCloses(): void
    {
        $html = $this->parser->parse("```\nunclosed content");

        $this->assertStringContainsString('<pre><code>', $html);
        $this->assertStringContainsString('</code></pre>', $html, 'Unclosed fence must be closed at end of input');
    }

    // ----------------------------------------------------------------- lists

    public function testParsesUnorderedLists(): void
    {
        $html = $this->parser->parse("* one\n* two\n- three");

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<li>two</li>', $html);
        $this->assertStringContainsString('<li>three</li>', $html);
        $this->assertStringContainsString('</ul>', $html);
    }

    public function testListTerminatesOnEmptyLine(): void
    {
        $html = $this->parser->parse("* one\n\nA paragraph after.");

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('</ul>', $html);
        $this->assertStringContainsString('<p>A paragraph after.</p>', $html);
    }

    public function testListTerminatesOnNonListItem(): void
    {
        $html = $this->parser->parse("* one\nPlain text after.");

        $this->assertStringContainsString('</ul>', $html);
        $this->assertStringContainsString('<p>Plain text after.</p>', $html);
    }

    public function testListWithInlineFormatting(): void
    {
        $html = $this->parser->parse('* **bold** item with `code`');

        $this->assertStringContainsString('<li><strong>bold</strong> item with <code>code</code></li>', $html);
    }

    // ------------------------------------------------------ tables (regression: /docs renders `| a | b |` rows as plain <p>)

    public function testParsesMarkdownTable(): void
    {
        $html = $this->parser->parse(
            "| Requirement | Version | Why |\n"
            . "| --- | --- | --- |\n"
            . "| PHP | 8.3 | runtime |\n"
            . "| Vue | 3.5 | UI |"
        );

        $this->assertStringContainsString('<table class="table">', $html, 'Tables must render as a <table>, not as plain <p> paragraphs.');
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('<th>Requirement</th>', $html);
        $this->assertStringContainsString('<tbody>', $html);
        $this->assertStringContainsString('<td>8.3</td>', $html);
        $this->assertStringContainsString('</table>', $html);
    }

    public function testParsesRealDocsTableWithEmptyLeadingHeaderCell(): void
    {
        $html = $this->parser->parse(
            "| | Schii (Phue\\Modules\\Schii) | REST (Phue\\Modules\\Rest) |\n"
            . "|---|---|---|\n"
            . "| Declaration | #[UI]/#[Field] attributes | #[Rest] attribute |\n"
            . "| Route prefix | apiPrefix (/api/schii) | prefix (/api) |"
        );

        $this->assertStringContainsString('<table class="table">', $html);
        $this->assertStringContainsString('<thead><tr><th></th><th>Schii', $html, 'The first header cell is intentionally empty.');
        $this->assertStringContainsString('<th>REST (Phue\\Modules\\Rest)</th>', $html);
        $this->assertStringNotContainsString('<td>---</td>', $html, 'The separator row must be consumed, never rendered as data.');
        $this->assertStringContainsString('<td>apiPrefix (/api/schii)</td>', $html);
    }

    public function testTableColonsProduceAlignmentClasses(): void
    {
        $html = $this->parser->parse(
            "| Key | Value |\n"
            . "| :--- | ---: |\n"
            . "| a | 1 |"
        );

        $this->assertStringContainsString('<table class="table">', $html);
        $this->assertStringContainsString('text-align:left', $html);
        $this->assertStringContainsString('text-align:right', $html);
    }

    public function testEscapedPipesInsideTableCellsAreLiteral(): void
    {
        $html = $this->parser->parse(
            "| Prop | Type |\n"
            . "| --- | --- |\n"
            . "| modelValue | `String \\| Number` |"
        );

        $this->assertStringContainsString('<table class="table">', $html);
        $this->assertStringContainsString('<td><code>String | Number</code></td>', $html, 'A \\| inside a cell must be a literal pipe, not a column separator.');
        $this->assertStringNotContainsString('<td>`String \\</td>', $html);
    }

    public function testEscapedPipesInTableHeaderDoNotBreakColumns(): void
    {
        $html = $this->parser->parse(
            "| Key | `A \\| B` | C |\n"
            . "| --- | --- | --- |\n"
            . "| 1 | 2 | 3 |"
        );

        $this->assertStringContainsString('<th>Key</th>', $html);
        $this->assertStringContainsString('<th><code>A | B</code></th>', $html);
        $this->assertStringContainsString('<th>C</th>', $html);
        $this->assertStringContainsString('<td>1</td><td>2</td><td>3</td>', $html);
    }

    public function testParagraphLookingLikeTableWithoutSeparatorStaysParagraph(): void
    {
        $html = $this->parser->parse("| not | a\n| table");

        $this->assertStringContainsString('<p>', $html);
        $this->assertStringNotContainsString('<table>', $html);
    }

    // ------------------------------------------------------- horizontal rule (regression: `---` renders as <p>---</p>)

    public function testParsesHorizontalRule(): void
    {
        $html = $this->parser->parse("Text before.\n---\nText after.");

        $this->assertStringContainsString('<hr>', $html, 'A lone `---` line must become an <hr>, not a <p>---</p>.');
        $this->assertStringNotContainsString('<p>---</p>', $html);
    }

    public function testHorizontalRuleVariants(): void
    {
        foreach (["---", "***", "___"] as $variant) {
            $html = $this->parser->parse($variant);
            $this->assertStringContainsString('<hr>', $html, "Variant {$variant} must be an <hr>.");
        }
    }

    public function testHeadingLikeDashesAreNotHorizontalRules(): void
    {
        // `---` spanning between text lines must be an <hr>;
        // but `### ---` remains a heading, and `a---b` remains inline text.
        $html = $this->parser->parse("### ---\n\nnot-an-hr---");

        $this->assertStringContainsString('<h3>---</h3>', $html);
    }

    // ------------------------------------------------------------ blockquotes

    public function testParsesBlockquote(): void
    {
        $html = $this->parser->parse("> A note.\n> Another line.");

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<p>A note.</p>', $html);
        $this->assertStringContainsString('<p>Another line.</p>', $html);
        $this->assertStringContainsString('</blockquote>', $html);
    }

    public function testBlockquoteTerminatesOnPlainLine(): void
    {
        $html = $this->parser->parse("> A quote.\n\nA paragraph after.");

        $this->assertStringContainsString('</blockquote>', $html);
        $this->assertStringContainsString('<p>A paragraph after.</p>', $html);
    }

    // ------------------------------------------------------------------ XSS

    public function testHtmlInTextIsEscaped(): void
    {
        $html = $this->parser->parse('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testHtmlInCodeBlockIsEscaped(): void
    {
        $html = $this->parser->parse("```\n<script>alert(1)</script>\n```");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testAttributeInjectionInTextIsEscaped(): void
    {
        $html = $this->parser->parse(\chr(34) . " onmouseover=" . chr(34) . "alert(1)");

        // Quotes become " — no raw attribute injection survives
        $this->assertStringNotContainsString("onmouseover=\"alert", $html);
        $this->assertStringContainsString("&" . "quot;", $html);
    }

    public function testLinkUrlSchemeIsSanitized(): void
    {
        // Security contract: javascript:/data: URIs must never survive as hrefs
        $html = $this->parser->parse('[click](javascript:alert(1))');

        $this->assertStringNotContainsString('href="javascript:', $html, 'javascript: URI must not survive as an href (XSS on click)');
    }

    public function testDataUrlSchemeIsSanitized(): void
    {
        $html = $this->parser->parse('[click](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)');

        $this->assertStringNotContainsString('href="data:', $html, 'data: URI must not survive as an href (XSS on click)');
    }
}
