<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

/**
 * A unit of deferred work.
 *
 * One method on purpose. Everything a job can declare beyond handle() is optional and lives
 * somewhere it cannot be forgotten:
 *
 *  - what it carries is read from the constructor, so adding a promoted property is all it takes
 *    to make a job carry a value (see PayloadCodec);
 *  - its queue, retry count and timeout come from the #[Job] attribute, which is one line at
 *    the top of the class and can be omitted entirely;
 *  - toPayload()/fromPayload() override the default encoding when reflection genuinely cannot
 *    express the state, and are the only optional methods a job ever needs.
 *
 * The tempting alternative — making a job implement serializePayload()/deserializePayload() —
 * costs twenty lines per job and a silent wrong-recipient bug the first time a constructor
 * property is added and one of the two halves is not updated. A contract that must be kept in
 * sync by hand is not an abstraction, it is a chore with type hints.
 *
 * @package Phue\Modules\Queue
 */
interface JobInterface
{
    /**
     * Do the work.
     *
     * Called once per attempt. Throwing anything other than the control-flow exceptions means
     * "this failed", and the retry policy decides what happens next — see RetryPolicy.
     *
     * @param JobContext $context Where this attempt stands, and the channel for releasing or
     *                            failing deliberately.
     */
    public function handle(JobContext $context): void;
}