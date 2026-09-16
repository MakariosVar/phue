<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Generator;

/**
 * Marker and contract for file-oriented generators that write output to disk.
 *
 * @package Phue\Modules\Schii\Generator
 */
interface FileGeneratorInterface extends GeneratorInterface
{
    /**
     * Returns the target output directory path.
     *
     * @return string
     */
    public function getOutputDir(): string;

    /**
     * Ensures that the output directory exists, creating it if necessary.
     *
     * @return void
     */
    public function ensureOutputDir(): void;
}