<?php

declare(strict_types=1);

namespace Phue\Tests\Compiler\Fixtures;

/**
 * Fixture class using banned extract() construct.
 */
class ExtractAotTargetClass
{
    public function unpack(array $data): array
    {
        extract($data);

        return get_defined_vars();
    }
}