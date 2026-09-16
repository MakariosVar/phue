<?php

declare(strict_types=1);

namespace Phue\Modules\Events\Attribute;

use Attribute;

/**
 * Attribute used to mark Active Record Models for automatic real-time event broadcasting.
 *
 * When applied to a model, CRUD operations (create, update, delete) will automatically
 * publish mutation events to the designated event channel.
 *
 * @package Phue\Modules\Events\Attribute
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Broadcastable
{
    /**
     * Initializes the Broadcastable attribute.
     *
     * @param string $channel Target event channel name (e.g., 'orders', 'products').
     * @param bool $broadcastCreate Whether to broadcast on model creation (default: true).
     * @param bool $broadcastUpdate Whether to broadcast on model update (default: true).
     * @param bool $broadcastDelete Whether to broadcast on model deletion (default: true).
     */
    public function __construct(
        public readonly string $channel,
        public readonly bool $broadcastCreate = true,
        public readonly bool $broadcastUpdate = true,
        public readonly bool $broadcastDelete = true
    ) {}
}
