<?php

declare(strict_types=1);

namespace Phue\Modules\Phii\Generator;

/**
 * Class FileMutation
 *
 * Represents an isolated file mutation with a target path and file content,
 * serving as the single source of writing operations.
 *
 * @package Phue\Modules\Phii\Generator
 */
final readonly class FileMutation
{
    /**
     * FileMutation constructor.
     *
     * @param string $path    The target absolute or relative file path.
     * @param string $content The text content to write into the file.
     */
    public function __construct(
        public string $path,
        public string $content
    ) {}

    /**
     * Ensures the target directory exists and writes the content to the file.
     *
     * @return bool True on success, false on failure.
     */
    public function write(): bool
    {
        $dir = dirname($this->path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        return file_put_contents($this->path, $this->content) !== false;
    }
}