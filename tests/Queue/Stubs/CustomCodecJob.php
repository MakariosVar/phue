<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job with a private constructor, encoding itself as a pair.
 */
final class CustomCodecJob implements JobInterface
{
    private function __construct(
        public readonly string $code
    ) {
    }

    public function handle(JobContext $context): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return ['code' => $this->code];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): static
    {
        return new self((string) ($payload['code'] ?? ''));
    }

    public static function make(string $code): self
    {
        return new self($code);
    }
}
