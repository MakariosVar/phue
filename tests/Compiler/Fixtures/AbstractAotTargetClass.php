<?php

declare(strict_types=1);

namespace Phue\Tests\Compiler\Fixtures;

/**
 * Abstract fixture class to verify AOT checker flags abstract types.
 */
abstract class AbstractAotTargetClass
{
    abstract public function run(): string;
}