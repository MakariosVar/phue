<?php

declare(strict_types=1);

namespace Phue\Core\Utils;

/**
 * Class MarkdownParser
 *
 * A lightweight, pure-PHP line-based state machine markdown parser with zero external dependencies.
 * Enforces XSS-safety by construction (escaping content before formatting transformations)
 * and URL scheme sanitization for links.
 * Supports headings, bold/italic, inline code, fenced code blocks, lists, links, blockquotes, and paragraphs.
 *
 * @package Phue\Core\Utils
 */
final class MarkdownParser
{
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

        foreach ($lines as $line) {
            // Handle Fenced Code Blocks (```)
            if (str_starts_with(trim($line), '```')) {
                if ($inCodeBlock) {
                    $htmlLines[] = '</code></pre>';
                    $inCodeBlock = false;
                } else {
                    if ($inList) {
                        $htmlLines[] = '</ul>';
                        $inList = false;
                    }
                    if ($inBlockquote) {
                        $htmlLines[] = '</blockquote>';
                        $inBlockquote = false;
                    }
                    $htmlLines[] = '<pre><code>';
                    $inCodeBlock = true;
                }
                continue;
            }

            if ($inCodeBlock) {
                // Escape code content securely
                $htmlLines[] = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }

            // Handle Blockquotes (>)
            if (str_starts_with(trim($line), '>')) {
                if ($inList) {
                    $htmlLines[] = '</ul>';
                    $inList = false;
                }
                if (!$inBlockquote) {
                    $htmlLines[] = '<blockquote>';
                    $inBlockquote = true;
                }
                $content = trim(substr(trim($line), 1));
                $htmlLines[] = '<p>' . $this->parseInline($content) . '</p>';
                continue;
            } elseif ($inBlockquote) {
                $htmlLines[] = '</blockquote>';
                $inBlockquote = false;
            }

            // Handle Unordered Lists (* or -)
            $trimmed = trim($line);
            if (preg_match('/^[\*\-]\s+(.+)$/', $trimmed, $matches)) {
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

            // Handle Headings (# to ######)
            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches)) {
                $level = strlen($matches[1]);
                $htmlLines[] = "<h{$level}>" . $this->parseInline($matches[2]) . "</h{$level}>";
                continue;
            }

            // Handle Empty lines / Paragraph breaks
            if ($trimmed === '') {
                $htmlLines[] = '';
                continue;
            }

            // Default: Paragraph text
            $htmlLines[] = '<p>' . $this->parseInline($trimmed) . '</p>';
        }

        // Close any remaining open structural tags
        if ($inCodeBlock) {
            $htmlLines[] = '</code></pre>';
        }
        if ($inList) {
            $htmlLines[] = '</ul>';
        }
        if ($inBlockquote) {
            $htmlLines[] = '</blockquote>';
        }

        return implode("\n", $htmlLines);
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