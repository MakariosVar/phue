<?php

declare(strict_types=1);

namespace Phue\Tests\Compiler\Fixtures;

/**
 * Interface fixture to verify AOT checker flags interface types.
 */
interface InterfaceAotTarget
{
    public function run(): string;
}