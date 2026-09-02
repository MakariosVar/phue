<?php

declare(strict_types=1);

namespace Phue\Tests\Compiler\Fixtures;

/**
 * Fixture class fully compliant with TypePHP AOT compilation rules.
 */
class ValidAotTargetClass
{
    public function __construct(
        private readonly string $serviceName = 'test'
    ) {}

    public function run(): string
    {
        return $this->serviceName;
    }
}