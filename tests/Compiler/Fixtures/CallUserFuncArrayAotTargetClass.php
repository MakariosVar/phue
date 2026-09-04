<?php

declare(strict_types=1);

namespace Phue\Tests\Compiler\Fixtures;

/**
 * Fixture class using banned dynamic call_user_func_array with a variable target.
 */
class CallUserFuncArrayAotTargetClass
{
    public function invoke(callable $cb, array $args): mixed
    {
        return call_user_func_array($cb, $args);
    }
}