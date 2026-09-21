<?php

declare(strict_types=1);

namespace Phue\Modules\Phii\Generator;

/**
 * Class FileMutation
 *
 * Represents an isolated file mutation with a target path and file content,
 * serving as the single source of atomic writing operations.
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
     * Ensures the target directory exists and performs an atomic write operation 
     * using a temporary file with exclusive locking followed by a rename.
     *
     * @return bool True on success, false on failure.
     */
    public function write(): bool
    {
        $dir = dirname($this->path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        // Generate a secure unique temporary file in the same directory for atomicity
        $tempFile = tempnam($dir, 'phii_mut_');
        if ($tempFile === false) {
            return false;
        }

        try {
            // Write content with exclusive lock to the temporary file
            $bytesWritten = file_put_contents($tempFile, $this->content, LOCK_EX);
            if ($bytesWritten === false) {
                @unlink($tempFile);
                return false;
            }

            // Atomically rename temporary file to target path
            if (!rename($tempFile, $this->path)) {
                @unlink($tempFile);
                return false;
            }

            // Ensure proper permissions on the target file
            @chmod($this->path, 0644);

            return true;
        } catch (\Throwable) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            return false;
        }
    }
}
