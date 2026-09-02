<?php

declare(strict_types=1);

namespace Phue\Compiler;

/**
 * Value Object holding AOT compatibility assessment results for a class.
 *
 * @package Phue\Compiler
 */
final class AotCompatibilityReport
{
    /**
     * @param string $className The fully qualified class name.
     * @param array<string> $issues List of identified dynamic dynamic/incompatible constructs.
     */
    public function __construct(
        public readonly string $className,
        public readonly array $issues = []
    ) {}

    /**
     * Indicates whether the class meets 100% of native AOT compilation rules.
     */
    public function isFullyCompatible(): bool
    {
        return empty($this->issues);
    }

    /**
     * Indicates whether the class contains non-critical dynamic features
     * requiring JIT fallback instead of full native compilation.
     */
    public function isPartiallyCompatible(): bool
    {
        return !empty($this->issues);
    }
}