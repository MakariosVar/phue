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

        $this->assertStringContainsString('<h1>H1</h1>', $html);
        $this->assertStringContainsString('<h2>H2</h2>', $html);
        $this->assertStringContainsString('<h3>H3</h3>', $html);
        $this->assertStringContainsString('<h4>H4</h4>', $html);
        $this->assertStringContainsString('<h5>H5</h5>', $html);
        $this->assertStringContainsString('<h6>H6</h6>', $html);
        $this->assertStringNotContainsString('<h7>', $html, '7+ # characters must not produce an h7 tag');
    }

    public function testHeadingWithInlineFormatting(): void
    {
        $html = $this->parser->parse('## Title with **bold** and `code`');

        $this->assertStringContainsString('<h2>Title with <strong>bold</strong> and <code>code</code></h2>', $html);
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
