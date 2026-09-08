<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use RuntimeException;

/**
 * Class PhueTemplateParser
 *
 * Compiles and parses single-file Phue component templates into static AST components 
 * and executes dynamic PHP blocks with persistent filesystem caching for OPCache optimization.
 *
 * @package Phue\Core\UI
 */
class PhueTemplateParser
{
    /**
     * Directory path where compiled view PHP files are stored, or null to use temp execution.
     *
     * @var string|null
     */
    private ?string $cacheDir;

    /**
     * PhueTemplateParser constructor.
     *
     * @param string|null $cacheDir Optional absolute or relative path to the views cache directory.
     */
    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir !== null ? rtrim($cacheDir, '/\\') : null;
    }

    /**
     * Parses full component directly with optional parameters and returns an evaluated PhueComponent instance.
     *
     * @param string               $filePath Absolute path to the .phue component file.
     * @param array<string, mixed> $params   Contextual parameters passed to the evaluated <php> block.
     *
     * @return PhueComponent Constructed and evaluated component representation.
     *
     * @throws RuntimeException If component structure is invalid or file cannot be read.
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
     * Parses ONLY static structure (template, script, raw phpCode) without executing embedded PHP.
     *
     * @param string $filePath Absolute path to the target component file.
     *
     * @return array{template: string, script: ?string, phpCode: ?string} Structural AST block map.
     *
     * @throws RuntimeException If file does not exist, is unreadable, or violates structure rules.
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

    /**
     * Parses raw single-file component content into distinct top-level tags (<php>, <template>, <script>).
     *
     * @param string $content  Raw file string content.
     * @param string $filePath Source path for exception contextual error logging.
     *
     * @return array<string, array{raw: string, body: string}> Parsed block definitions indexed by tag name.
     *
     * @throws RuntimeException If top-level tags are duplicated, malformed, or invalid content exists.
     */
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
     * Evaluates isolated PHP code blocks, utilizing disk caching for OPCache compliance when available.
     *
     * @param string               $phpCode Raw string containing PHP logic from <php> tag.
     * @param array<string, mixed> $params  Variables extracted into local closure scope.
     *
     * @return array<string, mixed> Evaluated state, title, and metadata payload array.
     *
     * @throws RuntimeException If cache directory cannot be created or execution files fail to write.
     */
    public function evaluatePhpBlock(string $phpCode, array $params = []): array
    {
        if (trim($phpCode) === '') {
            return ['title' => 'Phue App', 'state' => []];
        }

        $cacheDir = $this->cacheDir;

        $evaluator = static function (string $code, array $params) use ($cacheDir): array {
            extract($params, EXTR_SKIP);

            if ($cacheDir !== null) {
                if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                    throw new RuntimeException("Failed to create views cache directory: {$cacheDir}");
                }

                $hash = md5($code);
                $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'phue_' . $hash . '.php';

                if (!file_exists($cacheFile)) {
                    file_put_contents($cacheFile, "<?php\n" . $code . "\n", LOCK_EX);
                }

                $returned = include $cacheFile;
            } else {
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
            }

            return is_array($returned) ? $returned : [];
        };

        return $evaluator($phpCode, $params);
    }
}