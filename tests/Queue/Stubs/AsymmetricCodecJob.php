<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that encodes but cannot decode — a payload nothing could turn back into a job.
 */
final class AsymmetricCodecJob implements JobInterface
{
    public function __construct(public readonly string $value = 'x')
    {
    }

    public function handle(JobContext $context): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return ['value' => $this->value];
    }
}
