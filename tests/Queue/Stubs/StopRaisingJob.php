<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Core\Container;
use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;
use Phue\Modules\Queue\Worker\ManualStopSignal;

/**
 * A job that asks its worker to stop, from inside itself.
 *
 * The collaborator comes from the container rather than the constructor, and that is not a
 * workaround: a job is carried between processes as values, so it cannot hold an object at all.
 * Resolving collaborators from the container is the supported way for a job to reach anything, and
 * this stub exists partly to keep that honest — the codec refuses the other shape.
 */
final class StopRaisingJob implements JobInterface
{
    public function handle(JobContext $context): void
    {
        $signals = Container::getInstance()?->get(ManualStopSignal::class);

        if ($signals instanceof ManualStopSignal) {
            $signals->requestStop();
        }
    }
}
