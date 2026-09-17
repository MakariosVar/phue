<?php

declare(strict_types=1);

namespace Phue\Core\Utils;

/**
 * Class MarkdownParser
 *
 * A lightweight, pure-PHP line-based state machine markdown parser with zero external dependencies.
 * Enforces XSS-safety by construction (escaping content before formatting transformations)
 * and URL scheme sanitization for links.
 * Supports headings (with auto-generated anchor ids), bold/italic, inline code, fenced code
 * blocks, lists, links, blockquotes, tables (header extraction + per-column alignment),
 * horizontal rules, and paragraphs.
 *
 * @package Phue\Core\Utils
 */
final class MarkdownParser
{
    /**
     * @var list<string>
     */
    private array $codeLines = [];

    /**
     * Parses a Markdown string into HTML format.
     *
     * @param string $markdown Raw markdown content.
     * @return string Rendered HTML output.
     */
    public function parse(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown);
        if ($lines === false) {
            return '';
        }

        $htmlLines = [];
        $inCodeBlock = false;
        $inList = false;
        $inBlockquote = false;
        $paragraph = [];

        // Consecutive plain-text lines form a single paragraph (soft line breaks),
        // so inline formatting may span lines.
        $flushParagraph = function () use (&$htmlLines, &$paragraph): void {
            if ($paragraph === []) {
                return;
            }
            $htmlLines[] = '<p>' . $this->parseInline(implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        };

        // Table state machine.
        $inTable = false;
        // First table-looking row, buffered until the separator row confirms
        // whether it is a header (or, if no separator follows, a plain body row).
        $pendingHeader = null;
        // Per-column alignment parsed from the separator row (`:---`, `---:`, `:---:`).
        $tableAlign = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Handle Fenced Code Blocks (```)
            if (str_starts_with($trimmed, '```')) {
                $flushParagraph();
                if ($inCodeBlock) {
                    // Close: build the entire block as ONE htmlLine entry
                    $content = implode("\n", $this->codeLines);
                    $escaped = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $htmlLines[] = '<pre><code>' . $escaped . '</code></pre>';
                    $this->codeLines = [];
                    $inCodeBlock = false;
                } else {
                    // Open
                    if ($inList) {
                        $htmlLines[] = '</ul>';
                        $inList = false;
                    }
                    if ($inBlockquote) {
                        $htmlLines[] = '</blockquote>';
                        $inBlockquote = false;
                    }
                    $this->closeTable($htmlLines, $inTable, $pendingHeader, $tableAlign);
                    $this->codeLines = [];
                    $inCodeBlock = true;
                }
                continue;
            }

            if ($inCodeBlock) {
                // Escape code content securely
                $this->codeLines[] = $line;
                continue;
            }

            // Handle Tables (| Cell | Cell |)
            if ($this->isTableRow($trimmed)) {
                $flushParagraph();
                $cells = $this->splitRow($trimmed);
                $isSeparator = $this->isSeparatorRow($cells);

                if ($pendingHeader !== null) {
                    if ($isSeparator) {
                        // The buffered row was a header — wrap it in <thead>.
                        $tableAlign = $this->parseAlignments($cells);
                        $htmlLines[] = $this->openTableWithHeader($pendingHeader, $tableAlign);
                        $pendingHeader = null;
                        $inTable = true;
                    } else {
                        // No separator followed: the buffered row was plain data.
                        $htmlLines[] = '<table class="table"><tbody>';
                        $htmlLines[] = $this->renderRow($pendingHeader, 'td', null);
                        $pendingHeader = null;
                        $inTable = true;
                        $htmlLines[] = $this->renderRow($cells, 'td', null);
                    }
                    continue;
                }

                if ($inTable) {
                    // Separator rows inside a body are structural noise, not data.
                    if (!$isSeparator) {
                        $htmlLines[] = $this->renderRow($cells, 'td', $tableAlign);
                    }
                    continue;
                }

                // A stray separator without a preceding header is ignored.
                if (!$isSeparator) {
                    $pendingHeader = $cells;
                }
                continue;
            }

            // Any non-table line terminates an open (or pending) table.
            $this->closeTable($htmlLines, $inTable, $pendingHeader, $tableAlign);

            // Handle Blockquotes (>)
            if (str_starts_with($trimmed, '>')) {
                $flushParagraph();
                if ($inList) {
                    $htmlLines[] = '</ul>';
                    $inList = false;
                }
                if (!$inBlockquote) {
                    $htmlLines[] = '<blockquote>';
                    $inBlockquote = true;
                }
                $content = trim(substr($trimmed, 1));
                $htmlLines[] = '<p>' . $this->parseInline($content) . '</p>';
                continue;
            } elseif ($inBlockquote) {
                $htmlLines[] = '</blockquote>';
                $inBlockquote = false;
            }

            // Handle Unordered Lists (* or -)
            if (preg_match('/^[\*\-]\s+(.+)$/', $trimmed, $matches)) {
                $flushParagraph();
                if (!$inList) {
                    $htmlLines[] = '<ul>';
                    $inList = true;
                }
                $htmlLines[] = '<li>' . $this->parseInline($matches[1]) . '</li>';
                continue;
            } elseif ($inList && $trimmed === '') {
                $htmlLines[] = '</ul>';
                $inList = false;
                continue;
            } elseif ($inList && !preg_match('/^[\*\-]\s+/', $trimmed)) {
                $htmlLines[] = '</ul>';
                $inList = false;
            }

            // Handle Headings (# to ######) — with a slug anchor id for deep-linking.
            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches)) {
                $flushParagraph();
                $level = strlen($matches[1]);
                $slug = $this->slugify($matches[2]);
                $idAttr = $slug !== '' ? ' id="' . $slug . '"' : '';
                $htmlLines[] = "<h{$level}{$idAttr}>" . $this->parseInline($matches[2]) . "</h{$level}>";
                continue;
            }

            // Handle Horizontal Rules (---, ***, ___)
            if (preg_match('/^(?:\-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
                $flushParagraph();
                $htmlLines[] = '<hr>';
                continue;
            }

            // Handle Empty lines / Paragraph breaks
            if ($trimmed === '') {
                $flushParagraph();
                $htmlLines[] = '';
                continue;
            }

            // Default: paragraph text (buffered; consecutive lines join into one <p>)
            $paragraph[] = $trimmed;
        }

        // Close any remaining open structural tags
        $flushParagraph();
        if ($inCodeBlock) {
            $content = implode("\n", $this->codeLines);
            $escaped = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $htmlLines[] = '<pre><code>' . $escaped . '</code></pre>';
            $this->codeLines = [];
        }
        if ($inList) {
            $htmlLines[] = '</ul>';
        }
        if ($inBlockquote) {
            $htmlLines[] = '</blockquote>';
        }
        $this->closeTable($htmlLines, $inTable, $pendingHeader, $tableAlign);

        return implode("\n", $htmlLines);
    }

    // ------------------------------------------------------------- tables

    /**
     * Whether a trimmed line has the shape of a table row (leading + trailing pipe).
     */
    private function isTableRow(string $trimmed): bool
    {
        return strlen($trimmed) > 1
            && str_starts_with($trimmed, '|')
            && str_ends_with($trimmed, '|');
    }

    /**
     * Splits a `| a | b |` row into its trimmed cell values.
     *
     * @return list<string>
     */
    private function splitRow(string $trimmed): array
    {
        $inner = trim($trimmed, '|');

        // Split on unescaped pipes only — `\|` is a literal pipe inside a cell (GFM).
        $cells = preg_split('/(?<!\\\\)\|/', $inner);
        if ($cells === false) {
            return [$inner];
        }

        return array_map(
            static fn (string $cell): string => str_replace('\\|', '|', $cell),
            $cells
        );
    }

    /**
     * Whether every cell is a valid markdown table separator (`---`, `:---`, `---:`, `:---:`).
     */
    private function isSeparatorRow(array $cells): bool
    {
        if ($cells === []) {
            return false;
        }

        foreach ($cells as $cell) {
            if (preg_match('/^:?-{3,}:?$/', trim($cell)) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Maps separator cells to per-column alignment (`left`/`right`/`center`/null).
     *
     * @return list<string|null>
     */
    private function parseAlignments(array $cells): array
    {
        $alignments = [];

        foreach ($cells as $cell) {
            $cell = trim($cell);
            $left = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');

            $alignments[] = match (true) {
                $left && $right => 'center',
                $left => 'left',
                $right => 'right',
                default => null,
            };
        }

        return $alignments;
    }

    /**
     * Opens a Bootstrap-styled table with the first row promoted to a <thead>.
     */
    private function openTableWithHeader(array $headerCells, array $align): string
    {
        return '<table class="table">'
            . '<thead>' . $this->renderRow($headerCells, 'th', $align) . '</thead>'
            . '<tbody>';
    }

    /**
     * Renders one <tr> of cells, applying the column alignment when present.
     */
    private function renderRow(array $cells, string $tag, ?array $align): string
    {
        $row = '<tr>';

        foreach (array_values($cells) as $index => $cell) {
            $alignment = $align[$index] ?? null;
            $style = $alignment !== null ? ' style="text-align:' . $alignment . '"' : '';
            $row .= "<{$tag}{$style}>" . $this->parseInline(trim($cell)) . "</{$tag}>";
        }

        return $row . '</tr>';
    }

    /**
     * Flushes a pending single-row table and closes an open table body.
     *
     * @param list<string>      $htmlLines
     * @param list<string>|null $pendingHeader
     * @param list<string|null>|null $tableAlign
     */
    private function closeTable(array &$htmlLines, bool &$inTable, ?array &$pendingHeader, ?array &$tableAlign): void
    {
        if ($pendingHeader !== null) {
            $htmlLines[] = '<table class="table"><tbody>';
            $htmlLines[] = $this->renderRow($pendingHeader, 'td', null);
            $pendingHeader = null;
            $inTable = true;
        }

        if ($inTable) {
            $htmlLines[] = '</tbody></table>';
            $inTable = false;
            $tableAlign = null;
        }
    }

    /**
     * Builds a URL-safe anchor id from raw heading text (supports unicode).
     */
    private function slugify(string $text): string
    {
        $plain = html_entity_decode(
            strip_tags($this->parseInline($text)),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $slug = mb_strtolower($plain, 'UTF-8');
        $slug = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Parses inline markdown formatting (bold, italic, inline code, links) 
     * AFTER fully escaping raw text to ensure XSS-safety by construction.
     *
     * @param string $text Raw inline text string.
     * @return string Processed HTML inline string.
     */
    private function parseInline(string $text): string
    {
        // 1. CRITICAL SECURITY RULE: Escape all raw content first
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 2. Apply formatting transformations AFTER escaping.
        // Since special characters like `<`, `>`, `&`, `"`, `'` are already safely converted to HTML entities,
        // matching markdown syntax tokens (like backticks, asterisks, brackets) cannot introduce raw markup/XSS.

        // Inline Code (`code`)
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

        // Bold (**text** or __text__)
        $text = preg_replace('/\*\*([^\*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text);

        // Italic (*text* or _text_)
        $text = preg_replace('/\*([^\*]+)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/_([^_]+)_/', '<em>$1</em>', $text);

        // Links ([Title](url)) with scheme sanitization via preg_replace_callback
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ($matches) {
                $title = $matches[1];
                $url = trim(html_entity_decode($matches[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

                if ($this->isSafeUrl($url)) {
                    $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    return '<a href="' . $safeUrl . '">' . $title . '</a>';
                }

                // Neutralize unsafe schemes (e.g., javascript:, data:) by falling back to link-less text
                return $title;
            },
            $text
        );

        return $text;
    }

    /**
     * Validates if a URL is safe against XSS schemes (allows http, https, mailto, anchors, and relative paths starting with /).
     *
     * @param string $url The URL to validate.
     * @return bool True if safe, false otherwise.
     */
    private function isSafeUrl(string $url): bool
    {
        // Allow anchor links
        if ($url === '#' || str_starts_with($url, '#')) {
            return true;
        }

        // Allow relative paths starting with '/' but exclude protocol-relative URLs '//'
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }
}
