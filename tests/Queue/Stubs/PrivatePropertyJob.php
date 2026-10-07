<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job whose constructor argument has no readable property behind it.
 */
final class PrivatePropertyJob implements JobInterface
{
    private readonly string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function handle(JobContext $context): void
    {
    }
}
