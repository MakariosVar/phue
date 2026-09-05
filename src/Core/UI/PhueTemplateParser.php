<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use RuntimeException;

class PhueTemplateParser
{
    /**
     * Parses full component directly with optional params.
     */
    public function parse(string $filePath, array $params = []): PhueComponent
    {
        $structure = $this->parseStructure($filePath);
        $meta = $this->evaluatePhpBlock($structure['phpCode'] ?? '', $params);

        $title = (string) ($meta['title'] ?? 'Phue App');
        $state = is_array($meta['state'] ?? null) ? $meta['state'] : [];
        $scripts = is_array($meta['scripts'] ?? null) ? $meta['scripts'] : [];

        $view = new View(
            template: $structure['template'],
            state: $state,
            scripts: $scripts,
            inlineScript: $structure['script']
        );

        return new PhueComponent($title, $view);
    }

    /**
     * Parses ONLY static structure (template, script, raw phpCode) without executing PHP.
     *
     * @return array{template: string, script: ?string, phpCode: ?string}
     */
    public function parseStructure(string $filePath): array
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
            $occurrences = substr_count(strtolower($scriptBlock['raw']), '</script');
            if ($occurrences > 1) {
                throw new RuntimeException("Security Exception: <script> block in {$filePath} contains invalid literal closing script tag.");
            }
            $scriptContent = trim($scriptBlock['body']);
        }

        return [
            'template' => trim($templateContent),
            'script'   => $scriptContent,
            'phpCode'  => $phpContent,
        ];
    }

    private function parseTopLevelBlocks(string $content, string $filePath): array
    {
        $blocks = [];
        $offset = 0;
        $length = strlen($content);

        $offset += strspn($content, " \t\r\n", $offset);

        $standardPattern = '/\G\s*<(php|template)\b[^>]*>(.*?)<\/\1\s*>/smsi';
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
     * Evaluates the isolated PHP block with passed $params.
     */
    public function evaluatePhpBlock(string $phpCode, array $params = []): array
    {
        if (trim($phpCode) === '') {
            return ['title' => 'Phue App', 'state' => []];
        }

        $evaluator = static function (string $code, array $params): array {
            extract($params, EXTR_SKIP);

            $tmpFile = tempnam(sys_get_temp_dir(), 'phue_eval_');
            if ($tmpFile === false) {
                throw new RuntimeException('Failed to create temporary file for PHP evaluation');
            }

            file_put_contents($tmpFile, "<?php\n" . $code . "\n", LOCK_EX);

            try {
                $returned = include $tmpFile;
            } finally {
                @unlink($tmpFile);
            }

            return is_array($returned) ? $returned : [];
        };

        return $evaluator($phpCode, $params);
    }
}