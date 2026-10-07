<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

/**
 * A stand-in for an ActiveRecord model: an object with an id, and nothing that survives a
 * process boundary by accident.
 */
final class EntityStub
{
    public function __construct(public readonly int $id)
    {
    }
}
