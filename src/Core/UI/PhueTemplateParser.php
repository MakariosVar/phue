<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use RuntimeException;
use Throwable;
use ParseError;

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
     * @var array<string, string> Maps cached file path to original source file path
     */
    private array $sourceFileMap = [];

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
        $meta = $this->evaluatePhpBlock(
            $structure['phpCode'] ?? '',
            $params,
            $filePath,
            $structure['phpStartLine'] ?? 1
        );

        $title = (string) ($meta['title'] ?? 'Phue App');
        $state = is_array($meta['state'] ?? null) ? $meta['state'] : [];
        $scripts = is_array($meta['scripts'] ?? null) ? $meta['scripts'] : [];

        $view = new View(
            template: $structure['template'],
            state: $state,
            scripts: $scripts,
            inlineScript: $structure['script'],
            style: $structure['style']
        );

        return new PhueComponent($title, $view);
    }

    /**
     * Parses ONLY static structure (template, script, style, raw phpCode) without executing embedded PHP.
     *
     * @param string $filePath Absolute path to the target component file.
     *
     * @return array{template: string, script: ?string, style: ?string, phpCode: ?string, phpStartLine: int} Structural AST block map.
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
        $phpStartLine = $blocks['php']['startLine'] ?? 1;
        $templateContent = $blocks['template']['body'] ?? null;
        $scriptBlock = $blocks['script'] ?? null;
        $styleBlock = $blocks['style'] ?? null;

        if ($templateContent === null || trim($templateContent) === '') {
            throw new RuntimeException("Phue component is missing required <template> block: {$filePath}");
        }

        $scriptContent = null;
        if ($scriptBlock !== null) {
            $occurrences = substr_count(strtolower($scriptBlock['raw']), '</script');
            if ($occurrences > 1) {
                $scriptStartLine = $scriptBlock['startLine'] ?? 1;
                throw new RuntimeException("Security Exception: <script> block in {$filePath} on line {$scriptStartLine} contains invalid literal closing script tag.");
            }
            $scriptContent = trim($scriptBlock['body']);
        }

        $styleContent = null;
        if ($styleBlock !== null) {
            $styleContent = trim($styleBlock['body']);
        }

        return [
            'template' => trim($templateContent),
            'script' => $scriptContent,
            'style' => $styleContent,
            'phpCode' => $phpContent,
            'phpStartLine' => $phpStartLine,
        ];
    }

    /**
     * Parses raw single-file component content into distinct top-level tags (<php>, <template>, <script>, <style>).
     *
     * @param string $content  Raw file string content.
     * @param string $filePath Source path for exception contextual error logging.
     *
     * @return array<string, array{raw: string, body: string, startLine?: int}> Parsed block definitions indexed by tag name.
     *
     * @throws RuntimeException If top-level tags are duplicated, malformed, or invalid content exists.
     */
    private function parseTopLevelBlocks(string $content, string $filePath): array
    {
        $blocks = [];
        $offset = 0;
        $length = strlen($content);

        $offset += strspn($content, " \t\r\n", $offset);

        $scriptPattern = '/\G\s*<script\b[^>]*>(.*)(<\/script\s*>)/smsi';
        $stylePattern = '/\G\s*<style\b[^>]*>(.*)(<\/style\s*>)/smsi';
        $tagStartPattern = '/\G\s*<(php|template)\b[^>]*>/i';

        while ($offset < $length) {
            if (preg_match($tagStartPattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $tagName = strtolower($match[1][0]);
                $tagMatchOffset = $match[0][1];

                $startLine = substr_count(substr($content, 0, $tagMatchOffset), "\n") + 1;

                if (isset($blocks[$tagName])) {
                    throw new RuntimeException("Duplicate top-level <{$tagName}> section in {$filePath} on line {$startLine}");
                }

                $openingTag = $match[0][0];
                $openingTagLength = strlen($openingTag);
                $bodyStart = $tagMatchOffset + $openingTagLength;

                $depth = 1;
                $cursor = $bodyStart;
                $tokenPattern = '/<\/?' . preg_quote($tagName, '/') . '\b[^>]*>/i';

                while ($depth > 0 && preg_match($tokenPattern, $content, $tokenMatch, PREG_OFFSET_CAPTURE, $cursor)) {
                    $token = $tokenMatch[0][0];
                    $tokenOffset = $tokenMatch[0][1];

                    if (str_starts_with(strtolower($token), '</' . $tagName)) {
                        $depth--;
                    } else {
                        $depth++;
                    }

                    if ($depth === 0) {
                        $bodyEnd = $tokenOffset;
                        $closingTagLength = strlen($token);
                        break;
                    }

                    $cursor = $tokenOffset + strlen($token);
                }

                if ($depth !== 0) {
                    throw new RuntimeException("Unclosed top-level <{$tagName}> section in {$filePath} opened on line {$startLine}");
                }

                $body = substr($content, $bodyStart, $bodyEnd - $bodyStart);
                $rawLength = ($bodyEnd + $closingTagLength) - $tagMatchOffset;
                $raw = substr($content, $tagMatchOffset, $rawLength);

                $blocks[$tagName] = [
                    'raw' => $raw,
                    'body' => $body,
                    'startLine' => $startLine + 1,
                ];

                $offset = $tagMatchOffset + $rawLength;
                $offset += strspn($content, " \t\r\n", $offset);
            } elseif (preg_match($scriptPattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
                if (isset($blocks['script'])) {
                    $scriptStartLine = substr_count(substr($content, 0, $match[0][1]), "\n") + 1;
                    throw new RuntimeException("Duplicate top-level <script> section in {$filePath} on line {$scriptStartLine}");
                }

                $scriptStartLine = substr_count(substr($content, 0, $match[0][1]), "\n") + 1;

                $blocks['script'] = [
                    'raw' => $match[0][0],
                    'body' => $match[1][0],
                    'startLine' => $scriptStartLine,
                ];

                $offset += strlen($match[0][0]);
                $offset += strspn($content, " \t\r\n", $offset);
            } elseif (preg_match($stylePattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
                if (isset($blocks['style'])) {
                    $styleStartLine = substr_count(substr($content, 0, $match[0][1]), "\n") + 1;
                    throw new RuntimeException("Duplicate top-level <style> section in {$filePath} on line {$styleStartLine}");
                }

                $styleStartLine = substr_count(substr($content, 0, $match[0][1]), "\n") + 1;

                $blocks['style'] = [
                    'raw' => $match[0][0],
                    'body' => $match[1][0],
                    'startLine' => $styleStartLine,
                ];

                $offset += strlen($match[0][0]);
                $offset += strspn($content, " \t\r\n", $offset);
            } else {
                $remaining = substr($content, $offset);
                if (trim($remaining) !== '') {
                    $errorLine = substr_count(substr($content, 0, $offset), "\n") + 1;
                    $snippet = trim(substr($remaining, 0, 40));
                    throw new RuntimeException("Structural Error: Unexpected top-level content or malformed section at line {$errorLine}, offset {$offset} in {$filePath}. Found snippet: '{$snippet}'");
                }
                break;
            }
        }

        return $blocks;
    }

    /**
     * Evaluates isolated PHP code blocks, utilizing disk caching for OPCache compliance when available.
     *
     * @param string               $phpCode   Raw string containing PHP logic from <php> tag.
     * @param array<string, mixed> $params   Variables extracted into local closure scope.
     * @param string|null          $filePath  Original .phue file path for stack trace mapping.
     * @param int                  $startLine Starting line number of the <php> block in the source file.
     *
     * @return array<string, mixed> Evaluated state, title, and metadata payload array.
     *
     * @throws RuntimeException|Throwable If PHP evaluation fails or cache directory cannot be written.
     */
    public function evaluatePhpBlock(string $phpCode, array $params = [], ?string $filePath = null, int $startLine = 1): array
    {
        if (trim($phpCode) === '') {
            return ['title' => 'Phue App', 'state' => []];
        }

        $preparedCode = "<?php\n" . $phpCode;
        $cacheDir = $this->cacheDir;
        $codeHash = md5($phpCode);

        $evaluator = function (string $code, array $params, ?string $filePath, int $startLine) use ($cacheDir, $codeHash): array {
            extract($params, EXTR_SKIP);

            $targetFile = null;
            $isTempFile = false;

            try {
                if ($cacheDir !== null) {
                    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                        throw new RuntimeException("Failed to create views cache directory: {$cacheDir}");
                    }

                    $targetFile = $cacheDir . DIRECTORY_SEPARATOR . 'phue_' . $codeHash . '.php';

                    if (!file_exists($targetFile)) {
                        file_put_contents($targetFile, $code, LOCK_EX);
                    }
                } else {
                    $targetFile = tempnam(sys_get_temp_dir(), 'phue_eval_');
                    if ($targetFile === false) {
                        throw new RuntimeException('Failed to create temporary file for PHP evaluation');
                    }
                    $isTempFile = true;
                    file_put_contents($targetFile, $code, LOCK_EX);
                }

                if ($filePath !== null && $targetFile !== null) {
                    $this->sourceFileMap[$targetFile] = $filePath;
                }

                try {
                    $returned = include $targetFile;
                } catch (ParseError $e) {
                    $sourceFile = $this->sourceFileMap[$targetFile] ?? $e->getFile();
                    $sourceLine = $this->calculateSourceLine($targetFile, $e->getLine(), $startLine);

                    $reflection = new \ReflectionClass($e);

                    $fileProp = $reflection->getProperty('file');
                    $fileProp->setValue($e, $sourceFile);

                    $lineProp = $reflection->getProperty('line');
                    $lineProp->setValue($e, $sourceLine);

                    throw $e;
                } catch (Throwable $e) {
                    throw $e;
                }

                return is_array($returned) ? $returned : [];
            } finally {
                if ($isTempFile && $targetFile && file_exists($targetFile)) {
                    @unlink($targetFile);
                }
            }
        };

        return $evaluator($preparedCode, $params, $filePath, $startLine);
    }

    private function calculateSourceLine(string $cacheFile, int $errorLine, int $phpStartLine): int
    {
        return $phpStartLine + ($errorLine - 2);
    }
}