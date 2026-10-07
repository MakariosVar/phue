<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\Queue\Commands\AbstractQueueCommand;
use Phue\Modules\Queue\Commands\QueueFailedCommand;
use Phue\Modules\Queue\Commands\QueuePruneCommand;
use Phue\Modules\Queue\Commands\QueueStatusCommand;
use Phue\Modules\Queue\Commands\QueueWorkCommand;
use Phue\Modules\Queue\Driver\DatabaseQueueDriver;
use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobEnvelope;
use Phue\Modules\Queue\JobInterface;
use Phue\Modules\Queue\QueueManager;
use Phue\Tests\Queue\Stubs\RuntimeFailingJob;
use Phue\Tests\Queue\Stubs\RecordingJob;

/**
 * The commands, run rather than inspected.
 *
 * Each one is executed against a throwaway project on disk, because the interesting failures are
 * environmental — no connection, a table that was never created, a driver that has nothing to work
 * on — and none of those show up by looking at the code.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class QueueCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    private string $databaseFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseFile = (string) tempnam(sys_get_temp_dir(), 'phue_queue_cmd_');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->tempDirs as $dir) {
            foreach ((array) glob($dir . '/config/*.php') as $file) {
                @unlink((string) $file);
            }

            @rmdir($dir . '/config');
            @rmdir($dir);
        }

        $this->tempDirs = [];

        if ($this->databaseFile !== '' && file_exists($this->databaseFile)) {
            @unlink($this->databaseFile);
        }
    }

    // ------------------------------------------------------------------ queue:work

    /**
     * The most useful thing a queue can do is say so when it has nothing to do, because a worker
     * that exits silently having done nothing is indistinguishable from a healthy idle queue.
     */
    public function testWorkRefusesToPretendWithAnInlineDriver(): void
    {
        $result = $this->invoke(new QueueWorkCommand($this->project(['driver' => 'sync'])), []);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('nothing to work on', $result['err']);
        $this->assertStringContainsString('QUEUE_DRIVER=database', $result['err'], 'it says how to fix it');
        $this->assertSame('', $result['out']);
    }

    public function testWorkRunsAndDrainsWhatIsWaiting(): void
    {
        $project = $this->project(['driver' => 'database']);
        $this->install($project);

        $ran = $this->recordJobs($project, 3);

        $result = $this->invoke(new QueueWorkCommand($project), ['--max-jobs=10']);

        $this->assertSame(0, $result['code']);
        $this->assertSame('', $result['err']);
        $this->assertStringContainsString('drained', $result['out']);
        $this->assertStringContainsString('3 run', $result['out']);
        $this->assertCount(3, $ran, 'every job ran');
    }

    public function testWorkStopsAtTheJobLimit(): void
    {
        $project = $this->project(['driver' => 'database']);
        $this->install($project);
        $this->recordJobs($project, 5);

        $result = $this->invoke(new QueueWorkCommand($project), ['--max-jobs=2']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('job-limit', $result['out']);
        $this->assertStringContainsString('2 run', $result['out']);
    }

    public function testWorkOnceRunsExactlyOneJob(): void
    {
        $project = $this->project(['driver' => 'database']);
        $this->install($project);
        $this->recordJobs($project, 3);

        $result = $this->invoke(new QueueWorkCommand($project), ['--once']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('1 run', $result['out']);
    }

    public function testWorkCanBeLimitedToOneQueue(): void
    {
        $project = $this->project(['driver' => 'database']);
        $this->install($project);
        $this->recordJobs($project, 2, 'emails');
        $this->recordJobs($project, 1, 'reports');

        $result = $this->invoke(new QueueWorkCommand($project), ['--queue=emails', '--max-jobs=10']);

        $this->assertStringContainsString('2 run', $result['out']);
    }

    public function testWorkRejectsAnIllegalQueueName(): void
    {
        $project = $this->project(['driver' => 'database']);

        $result = $this->invoke(new QueueWorkCommand($project), ['--queue=team/emails']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('--queue', $result['err']);
    }

    public function testWorkRejectsANonNumericLimit(): void
    {
        $project = $this->project(['driver' => 'database']);

        $result = $this->invoke(new QueueWorkCommand($project), ['--max-jobs=lots']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('--max-jobs must be a whole number', $result['err']);
    }

    /**
     * ext-pcntl is not installed in this image, so --daemon cannot work. Saying so beats starting a
     * worker a supervisor will have to kill mid-job on every restart.
     */
    public function testWorkRefusesToRunForeverWithoutSignals(): void
    {
        $project = $this->project(['driver' => 'database']);
        $this->install($project);

        $result = $this->invoke(new QueueWorkCommand($project), ['--daemon']);

        if (function_exists('pcntl_signal')) {
            $this->assertSame(0, $result['code']);

            return;
        }

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('ext-pcntl', $result['err']);
        $this->assertStringContainsString('--max-jobs', $result['err'], 'it offers the alternative');
    }

    // ------------------------------------------------------------------ queue:status

    public function testStatusReportsAnEmptyQueueAndSaysWhatToDoNext(): void
    {
        $project = $this->project(['driver' => 'database']);
        $this->install($project);

        $result = $this->invoke(new QueueStatusCommand($project), []);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('pending', $result['out']);
        $this->assertStringContainsString('Nothing is waiting', $result['out']);
        $this->assertStringContainsString('queue:work', $result['out'], 'and names the command to run');
    }

    public function testStatusCountsWhatIsWaiting(): void
    {
        $project = $this->project(['driver' => 'database']);
        $this->install($project);
        $this->recordJobs($project, 4);

        $result = $this->invoke(new QueueStatusCommand($project), []);

        $this->assertStringContainsString('pending', $result['out']);
        $this->assertMatchesRegularExpression('/pending\s+4/', $result['out']);
    }

    public function testStatusPointsAtTheDeadLetterCommandWhenSomethingDied(): void
    {
        $project = $this->project(['driver' => 'database', 'max_tries' => 1]);
        $this->install($project);
        $this->recordFailingJob($project);

        $this->invoke(new QueueWorkCommand($project), ['--max-jobs=5']);
        $result = $this->invoke(new QueueStatusCommand($project), []);

        $this->assertMatchesRegularExpression('/failed\s+1/', $result['out']);
        $this->assertStringContainsString('queue:failed', $result['out']);
    }

    public function testStatusCanBeNarrowedToOneQueue(): void
    {
        $project = $this->project(['driver' => 'database']);
        $this->install($project);
        $this->recordJobs($project, 2, 'emails');

        $result = $this->invoke(new QueueStatusCommand($project), ['--queue=emails']);

        $this->assertStringContainsString('queue: emails', $result['out']);
        $this->assertMatchesRegularExpression('/pending\s+2/', $result['out']);
    }

    // ------------------------------------------------------------------ queue:failed

    public function testFailedSaysSoWhenNothingDied(): void
    {
        $project = $this->project(['driver' => 'database']);
        $this->install($project);

        $result = $this->invoke(new QueueFailedCommand($project), []);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('No failed jobs', $result['out']);
    }

    /**
     * A queue that reports "3 failed" is not information. Which job, carrying what, and why is the
     * whole reason the dead-letter table keeps the payload.
     */
    public function testFailedShowsWhatDiedAndWhy(): void
    {
        $project = $this->project(['driver' => 'database', 'max_tries' => 1]);
        $this->install($project);
        $this->recordFailingJob($project, 'the disk was full');

        $this->invoke(new QueueWorkCommand($project), ['--max-jobs=5']);
        $result = $this->invoke(new QueueFailedCommand($project), []);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('FailingJob', $result['out']);
        $this->assertStringContainsString('the disk was full', $result['out']);
        $this->assertStringContainsString('RuntimeException', $result['out']);
        $this->assertStringContainsString('payload:', $result['out']);
    }

    public function testFailedRespectsALimit(): void
    {
        $project = $this->project(['driver' => 'database', 'max_tries' => 1]);
        $this->install($project);
        $this->recordFailingJob($project, 'first');
        $this->recordFailingJob($project, 'second');

        $this->invoke(new QueueWorkCommand($project), ['--max-jobs=10']);

        $result = $this->invoke(new QueueFailedCommand($project), ['--limit=1']);

        $this->assertStringContainsString('1 shown', $result['out']);
    }

    // ------------------------------------------------------------------ queue:prune

    public function testPruneKeepsRecentJobsAndReportsWhatItDid(): void
    {
        $project = $this->project(['driver' => 'database']);
        $this->install($project);
        $this->recordJobs($project, 2);
        $this->invoke(new QueueWorkCommand($project), ['--max-jobs=5']);

        $result = $this->invoke(new QueuePruneCommand($project), []);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Removed 0', $result['out'], 'nothing is old enough yet');
        $this->assertStringContainsString('7 day', $result['out'], 'and says what the default was');
    }

    public function testPruneRemovesCompletedJobsAtZeroDays(): void
    {
        $project = $this->project(['driver' => 'database']);
        $this->install($project);
        $this->recordJobs($project, 2);
        $this->invoke(new QueueWorkCommand($project), ['--max-jobs=5']);

        $result = $this->invoke(new QueuePruneCommand($project), ['--days=0']);

        $this->assertStringContainsString('Removed 2', $result['out']);
    }

    public function testPruneKeepsDeadLettersEvenAtZeroDays(): void
    {
        $project = $this->project(['driver' => 'database', 'max_tries' => 1]);
        $this->install($project);
        $this->recordFailingJob($project);
        $this->invoke(new QueueWorkCommand($project), ['--max-jobs=5']);

        $this->invoke(new QueuePruneCommand($project), ['--days=0']);
        $status = $this->invoke(new QueueStatusCommand($project), []);

        $this->assertMatchesRegularExpression('/failed\s+1/', $status['out'], 'the record of what went wrong');
    }

    public function testPruneRejectsANonNumericAge(): void
    {
        $project = $this->project(['driver' => 'database']);

        $result = $this->invoke(new QueuePruneCommand($project), ['--days=ages']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('--days must be a whole number', $result['err']);
    }

    // ------------------------------------------------------------------ shared failures

    public function testEveryCommandFailsTheSameWayOnAnUninstalledTable(): void
    {
        $project = $this->project(['driver' => 'database']);

        foreach ([new QueueWorkCommand($project), new QueueStatusCommand($project), new QueueFailedCommand($project)] as $command) {
            $result = $this->invoke($command, ['--max-jobs=1']);

            $this->assertSame(1, $result['code'], $command::class);
            $this->assertStringContainsString('queue:install', $result['err'], $command::class);
            $this->assertStringNotContainsString('#0 ', $result['err'], 'no stack trace for an operator');
        }
    }

    public function testEveryCommandFailsTheSameWayWithNoDatabaseConfigured(): void
    {
        $dir = sys_get_temp_dir() . '/phue_queue_nodb_' . bin2hex(random_bytes(6));
        mkdir($dir . '/config', 0777, true);
        $this->tempDirs[] = $dir;

        file_put_contents(
            $dir . '/config/queue.php',
            '<?php return ' . var_export(['driver' => 'database'], true) . ';'
        );
        file_put_contents(
            $dir . '/config/database.php',
            '<?php return ' . var_export(['default' => 'sqlite', 'connections' => []], true) . ';'
        );

        foreach ([new QueueWorkCommand($dir), new QueueStatusCommand($dir)] as $command) {
            $result = $this->invoke($command, []);

            $this->assertSame(1, $result['code'], $command::class);
            $this->assertStringContainsString('config/database.php', $result['err'], $command::class);
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, string> $args
     *
     * @return array{code: int, out: string, err: string}
     */
    private function invoke(AbstractQueueCommand $command, array $args): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');

        $code = $command->execute($args, $out, $err);

        rewind($out);
        rewind($err);

        $result = [
            'code' => $code,
            'out' => (string) stream_get_contents($out),
            'err' => (string) stream_get_contents($err),
        ];

        fclose($out);
        fclose($err);

        return $result;
    }

    /**
     * A throwaway project whose queue is backed by this test's own sqlite file.
     *
     * @param array<string, mixed> $queue The queue section to write.
     */
    private function project(array $queue): string
    {
        $dir = sys_get_temp_dir() . '/phue_queue_cmd_' . bin2hex(random_bytes(6));
        mkdir($dir . '/config', 0777, true);
        $this->tempDirs[] = $dir;

        file_put_contents(
            $dir . '/config/queue.php',
            '<?php return ' . var_export(array_merge(['table' => 'queue_jobs'], $queue), true) . ';'
        );
        file_put_contents(
            $dir . '/config/database.php',
            '<?php return ' . var_export([
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => ['driver' => 'sqlite', 'database' => $this->databaseFile],
                ],
            ], true) . ';'
        );

        return $dir;
    }

    private function install(string $project): void
    {
        $result = $this->invoke(new \Phue\Modules\Queue\Commands\QueueInstallCommand($project), []);

        $this->assertSame(0, $result['code'], 'install: ' . $result['err']);
    }

    /**
     * Put n jobs on the queue by pushing them through the same manager a command would build.
     *
     * @return list<string> Job ids, so a test can tell what was stored.
     */
    private function recordJobs(string $project, int $count, string $queue = 'default'): array
    {
        return $this->push($project, $count, $queue, static fn(int $i): JobInterface => new RecordingJob($i));
    }

    private function recordFailingJob(string $project, string $why = 'it failed'): void
    {
        $this->push($project, 1, 'default', static fn(): JobInterface => new RuntimeFailingJob($why));
    }

    /**
     * @param callable(int): JobInterface $make
     *
     * @return list<string>
     */
    private function push(string $project, int $count, string $queue, callable $make): array
    {
        $command = new class($project) extends AbstractQueueCommand {
            public function getName(): string
            {
                return 'test';
            }

            public function getDescription(): string
            {
                return 'test';
            }

            public function getGroup(): string
            {
                return 'test';
            }

            public function execute(array $args = [], $out = \STDOUT, $err = \STDERR): int
            {
                return 0;
            }

            public function queue(): QueueManager
            {
                return $this->manager();
            }
        };

        $manager = $command->queue();
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $manager->push($make($i), $queue);
        }

        return $ids;
    }
}