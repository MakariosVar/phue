<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

/**
 * Not a job. Present so that the class check has something to catch.
 */
final class NotAJob
{
    public function __construct(public readonly string $value = 'x')
    {
    }
}
