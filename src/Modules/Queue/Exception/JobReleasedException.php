<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Exception;

/**
 * Control flow: the job asked to be put back on the queue instead of completing.
 *
 * A thrown exception rather than a flag on the context, because the job decides at one exact
 * point and the manager has to honour exactly one decision. A flag would still be set after a
 * job that called release() and then threw on its way out, leaving the manager to choose
 * between two contradictory signals.
 *
 * @package Phue\Modules\Queue\Exception
 */
final class JobReleasedException extends \RuntimeException
{
    /**
     * @param int $delaySeconds How long before the job may be claimed again. Zero means now.
     */
    public function __construct(public readonly int $delaySeconds = 0)
    {
        parent::__construct('Job released back to the queue.');
    }
}