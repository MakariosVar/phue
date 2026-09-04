<?php

declare(strict_types=1);

namespace Phue\Tests\Compiler\Fixtures;

/**
 * Fixture class using banned variable-variable ($$var) construct.
 */
class VariableVariableAotTargetClass
{
    public function access(string $name): mixed
    {
        $value = 'x';

        return $$name;
    }
}