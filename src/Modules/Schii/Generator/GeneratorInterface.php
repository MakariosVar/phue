<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Generator;

/**
 * Base generator contract representing a generator's identity.
 *
 * @package Phue\Modules\Schii\Generator
 */
interface GeneratorInterface
{
    /**
     * Returns the unique name/identifier of the generator.
     *
     * @return string
     */
    public function name(): string;
}