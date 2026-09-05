<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use RuntimeException;

/**
 * Single-pass strict structural parser for .phue SFCs using \G continuous anchoring.
 *
 * @package Phue\Core\UI
 */
class PhueTemplateParser
{
    /**
     * Parse a .phue file into a structured PhueComponent.
     *
     * @param string $filePath Absolute path to the .phue file.
     * @return PhueComponent Fully parsed component metadata and View object.
     * @throws RuntimeException On I/O, structural errors, missing template, or security violations.
     */
    public function parse(string $filePath): PhueComponent
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Phue template file not found: {$filePath}");
        }

        $rawContent = file_get_contents($filePath);
        if ($rawContent === false) {
            throw new RuntimeException("Unable to read Phue template file: {$filePath}");
        }

        $blocks = $this->parseTopLevelBlocks($rawContent, $filePath);

        $phpContent = $blocks['php']['body'] ?? null;
        $templateContent = $blocks['template']['body'] ?? null;
        $scriptBlock = $blocks['script'] ?? null;

        if ($templateContent === null || trim($templateContent) === '') {
            throw new RuntimeException("Phue component is missing required <template> block: {$filePath}");
        }

        $scriptContent = null;
        if ($scriptBlock !== null) {
            // Count occurrences across full raw span
            $occurrences = substr_count(strtolower($scriptBlock['raw']), '</script');
            if ($occurrences > 1) {
                throw new RuntimeException("Security Exception: <script> block in {$filePath} contains invalid literal closing script tag.");
            }
            $scriptContent = trim($scriptBlock['body']);
        }

        $meta = $this->evaluatePhpBlock($phpContent);

        $title = (string) ($meta['title'] ?? 'Phue Application');
        $state = is_array($meta['state'] ?? null) ? $meta['state'] : [];
        $scripts = is_array($meta['scripts'] ?? null) ? $meta['scripts'] : [];

        $view = new View(
            template: trim($templateContent),
            state: $state,
            scripts: $scripts,
            inlineScript: $scriptContent
        );

        return new PhueComponent($title, $view);
    }

    /**
     * Parses top-level blocks in continuous sequence via \G anchoring.
     *
     * @return array<string, array{raw: string, body: string}>
     */
    private function parseTopLevelBlocks(string $content, string $filePath): array
    {
        $blocks = [];
        $offset = 0;
        $length = strlen($content);

        // Advance over initial whitespace
        $offset += strspn($content, " \t\r\n", $offset);

        // Pattern for standard tags (<php>, <template>)
        $standardPattern = '/\G\s*<(php|template)\b[^>]*>(.*?)<\/\1\s*>/smsi';
        
        // Pattern for <script> tag: match greedily to the last </script> tag in sequence
        $scriptPattern = '/\G\s*<script\b[^>]*>(.*)(<\/script\s*>)/smsi';

        while ($offset < $length) {
            if (preg_match($standardPattern, $content, $match, 0, $offset)) {
                $tagName = strtolower($match[1]);

                if (isset($blocks[$tagName])) {
                    throw new RuntimeException("Duplicate top-level <{$tagName}> section in {$filePath}");
                }

                $blocks[$tagName] = [
                    'raw'  => $match[0],
                    'body' => $match[2],
                ];

                $offset += strlen($match[0]);
                $offset += strspn($content, " \t\r\n", $offset);
            } elseif (preg_match($scriptPattern, $content, $match, 0, $offset)) {
                if (isset($blocks['script'])) {
                    throw new RuntimeException("Duplicate top-level <script> section in {$filePath}");
                }

                $blocks['script'] = [
                    'raw'  => $match[0],
                    'body' => $match[1],
                ];

                $offset += strlen($match[0]);
                $offset += strspn($content, " \t\r\n", $offset);
            } else {
                $remaining = substr($content, $offset);
                if (trim($remaining) !== '') {
                    throw new RuntimeException("Structural Error: Unexpected top-level content or malformed section at offset {$offset} in {$filePath}");
                }
                break;
            }
        }

        return $blocks;
    }

    /**
     * Evaluates PHP block content securely using an unguessable isolated file context.
     *
     * @return array<string, mixed>
     */
    private function evaluatePhpBlock(?string $code): array
    {
        if ($code === null || trim($code) === '') {
            return [];
        }

        $randomSuffix = bin2hex(random_bytes(16));
        $tmpFile = sys_get_temp_dir() . '/phue_block_' . $randomSuffix . '.php';

        file_put_contents($tmpFile, "<?php\n" . $code, LOCK_EX);

        $executor = static function (string $codeStreamPath): array {
            $result = include $codeStreamPath;
            return is_array($result) ? $result : [];
        };

        try {
            return $executor($tmpFile);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}