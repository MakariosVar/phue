<?php

declare(strict_types=1);

namespace Phue\Tests\Compiler\Fixtures;

/**
 * Fixture class containing banned dynamic constructs (eval).
 */
class IncompatibleAotTargetClass
{
    public function executeDynamicCode(string $code): mixed
    {
        return eval($code);
    }
}