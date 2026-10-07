<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Exception;

/**
 * Marks a failure that must not be retried.
 *
 * Retry policy is a guess: without this, a job that fails because the input was invalid gets
 * three more attempts and three more copies of the same dead-letter row, and an operator learns
 * to ignore the dead-letter store. Throwing this says the outcome cannot change on its own, so
 * the job goes straight to the dead-letter store with the real reason attached.
 *
 * @package Phue\Modules\Queue\Exception
 */
final class NonRetryableException extends \RuntimeException
{
}