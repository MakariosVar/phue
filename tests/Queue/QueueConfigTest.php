<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Config;
use Phue\Modules\Queue\QueueConfig;

/**
 * Whether a configuration mistake is a boot failure or a surprise on the first retry.
 *
 * These values are read from a file nobody compiles, one value is interpolated into SQL, and one
 * is a namespace boundary on class instantiation. Each test here is one of those three concerns,
 * checked at the moment the config is resolved rather than at the moment the value is used.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class QueueConfigTest extends TestCase
{
    /** @var list<string> Temporary config directories to clean up. */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->tempDirs as $dir) {
            foreach ((array) glob($dir . '/*.php') as $file) {
                @unlink((string) $file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];
    }

    public function testAnAbsentSectionYieldsUsableDefaults(): void
    {
        $config = QueueConfig::fromArray([]);

        $this->assertSame('sync', $config->driver);
        $this->assertSame('default', $config->queue);
        $this->assertSame(3, $config->maxTries);
        $this->assertSame(60, $config->leaseSeconds);
    }

    /**
     * A project that removed the module from config/app.php still has the file in the framework's
     * own config directory, and a test booting over a temporary directory has nothing at all.
     * Neither may be an error.
     */
    public function testAConfigDirectoryWithNoQueueFileStillResolves(): void
    {
        $config = QueueConfig::fromContainer($this->configFor([]));

        $this->assertSame('sync', $config->driver);
    }

    // ------------------------------------------------------------ names that reach into SQL

    /**
     * @return array<string, array{0: string}>
     */
    public static function illegalTableNames(): array
    {
        return [
            'injection' => ['queue_jobs; DROP TABLE users'],
            'qualified' => ['other.queue_jobs'],
            'quoted' => ['`queue_jobs`'],
            'empty' => [''],
            'leading digit' => ['1queue'],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('illegalTableNames')]
    public function testATableNameThatIsNotAPlainIdentifierIsRefused(string $table): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/queue\.table/');

        QueueConfig::fromArray(['table' => $table]);
    }

    public function testAPlainTableNameIsAccepted(): void
    {
        $this->assertSame('queue_jobs_2026', QueueConfig::fromArray(['table' => 'queue_jobs_2026'])->table);
    }

    // ------------------------------------------------------------ names that reach into storage

    /**
     * @return array<string, array{0: string}>
     */
    public static function illegalQueueNames(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['emails queue'],
            'slash' => ['team/emails'],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('illegalQueueNames')]
    public function testAnIllegalQueueNameIsRefused(string $queue): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/queue\.queue/');

        QueueConfig::fromArray(['queue' => $queue]);
    }

    // ------------------------------------------------------------ values that cannot mean anything

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function nonsenseValues(): array
    {
        return [
            'no tries' => [['max_tries' => 0], '/queue\.max_tries is 0/'],
            'no lease' => [['lease_seconds' => 0], '/queue\.lease_seconds is 0/'],
            'tiny payload cap' => [['max_payload_bytes' => 10], '/queue\.max_payload_bytes is 10/'],
            'empty driver' => [['driver' => ''], '/queue\.driver must not be empty/'],
            'backoff ceiling below floor' => [
                ['backoff_base' => 600, 'backoff_max' => 5],
                '/queue\.backoff_max \(5\) is below queue\.backoff_base \(600\)/',
            ],
        ];
    }

    #[DataProvider('nonsenseValues')]
    public function testAValueThatCannotMeanAnythingIsRefused(array $raw, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($message);

        QueueConfig::fromArray($raw);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function mistypedValues(): array
    {
        return [
            'tries as text' => [['max_tries' => 'lots'], '/queue\.max_tries must be an integer, got string/'],
            'jitter as int' => [['backoff_jitter' => 1], '/queue\.backoff_jitter must be a boolean/'],
            'namespaces as text' => [['job_namespaces' => 'App'], '/queue\.job_namespaces must be an array/'],
            'namespaces with a hole' => [['job_namespaces' => ['App', 7]], '/only non-empty strings/'],
        ];
    }

    #[DataProvider('mistypedValues')]
    public function testAMistypedValueNamesTheKeyItCameFrom(array $raw, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($message);

        QueueConfig::fromArray($raw);
    }

    // ------------------------------------------------------------ drivers

    public function testAnExtraDriverMustImplementTheContract(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        QueueConfig::fromArray(['drivers' => ['mine' => \stdClass::class]]);
    }

    public function testAnExtraDriverMustExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must name a class that exists/');

        QueueConfig::fromArray(['drivers' => ['mine' => 'Phue\\Modules\\Queue\\NoSuchDriver']]);
    }

    public function testAValidExtraDriverIsAccepted(): void
    {
        $config = QueueConfig::fromArray([
            'drivers' => ['sync' => \Phue\Modules\Queue\Driver\SyncQueueDriver::class],
        ]);

        $this->assertArrayHasKey('sync', $config->extraDrivers);
    }

    // ------------------------------------------------------------ the backoff curve

    public function testTheFirstRetryWaitsTheBaseAndEachOneAfterDoubles(): void
    {
        $config = QueueConfig::fromArray([
            'backoff_base' => 5,
            'backoff_max' => 100,
            'backoff_jitter' => false,
        ]);

        $this->assertSame(5, $config->backoffDelay('job-1', 1));
        $this->assertSame(10, $config->backoffDelay('job-1', 2));
        $this->assertSame(20, $config->backoffDelay('job-1', 3));
        $this->assertSame(40, $config->backoffDelay('job-1', 4));
    }

    public function testTheCurveStopsAtItsCeiling(): void
    {
        $config = QueueConfig::fromArray([
            'backoff_base' => 5,
            'backoff_max' => 60,
            'backoff_jitter' => false,
        ]);

        $this->assertSame(60, $config->backoffDelay('job-1', 10));
        $this->assertSame(60, $config->backoffDelay('job-1', 1000));
    }

    public function testJitterOnlyEverAddsTimeAndNeverPastTheCeilingPlusHalfOfIt(): void
    {
        $config = QueueConfig::fromArray([
            'backoff_base' => 10,
            'backoff_max' => 100,
            'backoff_jitter' => true,
        ]);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $uncapped = (int) min(10 * (2 ** ($attempt - 1)), 100);
            $delay = $config->backoffDelay('job-' . $attempt, $attempt);

            $this->assertGreaterThanOrEqual($uncapped, $delay, 'jitter must only add time');
            $this->assertLessThanOrEqual($uncapped + intdiv($uncapped, 2), $delay, 'and stay bounded');
        }
    }

    /**
     * Two workers must not be able to compute the same retry time for the same job, or jitter has
     * done nothing at all. Two calls with the same inputs have to agree as well, or a test cannot
     * assert a delay without sleeping.
     */
    public function testTheSpreadIsStablePerJobAndAttemptButDiffersBetweenThem(): void
    {
        $config = QueueConfig::fromArray(['backoff_jitter' => true]);
        $seen = [];

        for ($job = 0; $job < 40; $job++) {
            $first = $config->spread('job-' . $job, 1, 100);
            $this->assertSame($first, $config->spread('job-' . $job, 1, 100), 'must be stable');
            $seen[$job] = $first;
        }

        $this->assertGreaterThan(1, count(array_unique($seen)), 'must actually spread');
    }

    public function testAJobIdAndAttemptSpreadDifferently(): void
    {
        $config = QueueConfig::fromArray(['backoff_jitter' => true]);

        $this->assertNotSame(
            $config->spread('job-1', 1, 10_000),
            $config->spread('job-1', 2, 10_000)
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function configFor(array $raw): Config
    {
        $dir = sys_get_temp_dir() . '/phue_queue_cfg_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        file_put_contents($dir . '/app.php', '<?php return ["debug" => false];');

        if ($raw !== []) {
            file_put_contents($dir . '/queue.php', '<?php return ' . var_export($raw, true) . ';');
        }

        return new Config($dir);
    }
}