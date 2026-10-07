<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Modules\Queue\Commands\QueueInstallCommand;
use Phue\Modules\Queue\Driver\DatabaseQueueDriver;
use Phue\Modules\Queue\JobEnvelope;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\Schema\QueueSchema;

/**
 * The command somebody reaches for when the queue is already broken.
 *
 * Executed rather than inspected, because the interesting failures are environmental — no
 * configuration, no connection, an unreachable database — and every one of those has to produce a
 * sentence on stderr and exit 1. A stack trace in a console is a worse answer than a sentence
 * when the person reading it is an operator with a queue that is not draining.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class QueueInstallCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

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
    }

    // ------------------------------------------------------------------ as registered

    public function testTheCommandIsNamedGroupedAndDescribed(): void
    {
        $command = new QueueInstallCommand();

        $this->assertSame('queue:install', $command->getName());
        $this->assertSame('Queue', $command->getGroup());
        $this->assertStringContainsString('repeatedly', $command->getDescription());
        $this->assertStringContainsString('jobs are kept', $command->getDescription());
    }

    /**
     * bin/phue builds commands with a bare `new`, so the constructor has to work with no
     * arguments at all.
     */
    public function testItCanBeConstructedWithoutArguments(): void
    {
        $this->assertInstanceOf(QueueInstallCommand::class, new QueueInstallCommand());
    }

    // ------------------------------------------------------------------ the happy path

    public function testItCreatesTheTablesAndSaysWhatItDid(): void
    {
        $result = $this->invoke($this->project([
            'queue' => ['table' => 'queue_jobs', 'driver' => 'database'],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ]));

        $this->assertSame(0, $result['code']);
        $this->assertSame('', $result['err']);
        $this->assertStringContainsString('created', $result['out']);
        $this->assertStringContainsString('queue_jobs', $result['out']);
        $this->assertStringContainsString('queue_jobs_failed', $result['out']);
        $this->assertStringContainsString('queue_jobs_meta', $result['out']);
    }

    public function testRunningItTwiceSaysThereIsNothingToDo(): void
    {
        // A file rather than :memory:, because an in-memory database is a different one on every
        // invocation and the second run would find a clean schema and appear to work.
        $database = tempnam(sys_get_temp_dir(), 'phue_queue_db_');
        $dir = $this->project([
            'queue' => ['table' => 'queue_jobs', 'driver' => 'database'],
            'database' => [
                'default' => 'sqlite',
                'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $database]],
            ],
        ]);

        $this->assertSame(0, $this->invokeAt($dir)['code']);
        $second = $this->invokeAt($dir);

        $this->assertSame(0, $second['code']);
        $this->assertStringContainsString('already at version', $second['out']);
        $this->assertSame('', $second['err']);

        @unlink((string) $database);
    }

    // ------------------------------------------------------------------ the unhappy paths

    public function testAProjectWithNoDatabaseFailsWithAMessageAndNotAStackTrace(): void
    {
        $result = $this->invoke($this->project([
            'queue' => ['table' => 'queue_jobs', 'driver' => 'database'],
            'database' => ['default' => 'sqlite', 'connections' => []],
        ]));

        $this->assertSame(1, $result['code']);
        $this->assertStringNotContainsString('#0 ', $result['err'], 'an operator does not need a stack trace');
        $this->assertStringContainsString('connection', $result['err']);
        $this->assertStringContainsString('config/database.php', $result['err']);
        $this->assertSame('', $result['out'], 'a failure says nothing on stdout');
    }

    public function testAMalformedQueueConfigFailsOnTheKeyItCameFrom(): void
    {
        $result = $this->invoke($this->project([
            'queue' => ['table' => 'queue_jobs; DROP TABLE users', 'driver' => 'database'],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ]));

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('queue.table', $result['err']);
        $this->assertSame('', $result['out']);
    }

    public function testAnUnreachableDatabaseIsReportedAsSuch(): void
    {
        $result = $this->invoke($this->project([
            'queue' => ['table' => 'queue_jobs', 'driver' => 'database'],
            'database' => [
                'default' => 'nowhere',
                'connections' => ['nowhere' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1]],
            ],
        ]));

        $this->assertSame(1, $result['code']);
        $this->assertStringNotContainsString('#0 ', $result['err']);
    }

    // ------------------------------------------------------------------ what "succeeded" means

    /**
     * Not "exit 0": the tables exist and the driver works against them. An install that reports
     * success and leaves the queue unusable is worse than one that fails.
     */
    public function testAfterInstallingTheDriverWorks(): void
    {
        $connection = \Phue\Tests\Support\SqliteFactory::memory();
        $config = QueueConfig::fromArray(['driver' => 'database', 'table' => 'queue_jobs']);
        (new QueueSchema($connection, $config->table))->install();

        $driver = new DatabaseQueueDriver($connection, $config);
        $id = $driver->push(JobEnvelope::forJob('Some\\Job', ['a' => 1], 'default'));

        $this->assertNotSame('', $id);
        $this->assertSame(1, $driver->stats()['pending']);
        $this->assertSame(QueueSchema::VERSION, (new QueueSchema($connection, 'queue_jobs'))->installedVersion());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Run the command against the running project.
     *
     * @return array{code: int, out: string, err: string}
     */
    private function invoke(?string $basePath = null): array
    {
        return $this->invokeAt($basePath);
    }

    /**
     * Run the command against a given project root, capturing both streams.
     *
     * @return array{code: int, out: string, err: string}
     */
    private function invokeAt(?string $basePath): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');

        $code = (new QueueInstallCommand($basePath))->execute([], $out, $err);

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
     * A throwaway project directory with the given config files in it.
     *
     * @param array<string, array<string, mixed>> $files File name to contents, without the .php.
     */
    private function project(array $files): string
    {
        $dir = sys_get_temp_dir() . '/phue_queue_cli_' . bin2hex(random_bytes(6));
        mkdir($dir . '/config', 0777, true);
        $this->tempDirs[] = $dir;

        foreach ($files as $name => $contents) {
            file_put_contents(
                sprintf('%s/config/%s.php', $dir, $name),
                '<?php return ' . var_export($contents, true) . ';'
            );
        }

        return $dir;
    }
}