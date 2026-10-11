<?php

declare(strict_types=1);

namespace Phue\Tests\Suite;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A deliberately skipped test, kept so the skip path stays exercised.
 *
 * Why this exists
 * ---------------
 * `bin/phue_test` counts PHPUnit's numbered output sections to decide what to
 * record in `.phpunit.cache/phue-test.json`. With `--display-skipped`, skipped
 * tests are printed in the same numbered shape as failures, under their own
 * section header. A run that skipped one test and passed everything else was
 * therefore recorded as a failure — and the next `--only-failed` went looking
 * for a defect that was never there.
 *
 * That regression is invisible without a test that actually skips. This
 * environment has no such test: every capability-gated suite (Redis, Swoole,
 * the JavaScript parser) is either present or absent by configuration, and the
 * one test that used to skip unconditionally went away with the compiler
 * module. A guard with nothing to guard is not a guard.
 *
 * So the fixture is stated rather than borrowed from the environment: this test
 * skips, always, for a reason written down, and
 * `Phue\Tests\Core\Console\PhueTestCliTest::testASkippedTestIsNotRecordedAsAFailure`
 * filters on it to prove the runner's accounting.
 *
 * @package Phue\Tests\Suite
 */
#[Group('unit')]
final class SkipPathFixtureTest extends TestCase
{
    public function testSkipsUnconditionally(): void
    {
        $this->markTestSkipped(
            'Deliberate skip: exercises the runner\'s skip accounting. '
            . 'This test is never expected to run — see the class docblock.'
        );
    }
}