<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

use Phue\Core\Config;

/**
 * Everything the queue can be configured with, resolved once and validated once.
 *
 * The validation is the point of this class rather than an afterthought. Queue settings are read
 * from a file that is not compiled, and the values are used as a table name in SQL and as a
 * namespace boundary on class instantiation. A typo in a queue name that only surfaces on the
 * first retry, in a worker, hours after the deploy, is the failure mode worth designing against.
 *
 * So: nothing is read lazily at the point of use, everything is checked when the module boots,
 * and a bad value names the key it came from.
 *
 * @package Phue\Modules\Queue
 */
final readonly class QueueConfig
{
    /**
     * Legal queue names. A queue name ends up in a stored column and in a CLI argument, so the
     * alphabet is narrow on purpose.
     */
    public const QUEUE_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    /**
     * Table names are interpolated into SQL, so nothing but an identifier is permitted. There is
     * no prefix, suffix or quoting path that makes an arbitrary string safe here, which is why
     * this is a whitelist rather than an escape.
     */
    public const TABLE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    /**
     * @param string            $driver         Registered driver name, or a class implementing
     *                                           QueueDriverInterface.
     * @param string            $queue          Queue used when neither the caller nor the job
     *                                           names one.
     * @param int               $maxTries      Attempts before a job is dead-lettered.
     * @param int               $backoffBase    First retry delay in seconds.
     * @param int               $backoffMax     Ceiling for the exponential curve.
     * @param bool              $backoffJitter  Spread retries so a batch that failed together
     *                                           does not come back together.
     * @param int               $leaseSeconds   How long a reserved job may be held before another
     *                                           worker may take it.
     * @param int               $maxPayloadBytes Rejected at push rather than stored and
     *                                           discovered at run time.
     * @param string            $table          Dead-letter table for the database driver.
     * @param list<string>      $jobNamespaces  Namespaces jobs may be loaded from. Empty means
     *                                           unrestricted, which is a real decision and not an
     *                                           oversight — see QueuePayloadException::disallowedJobClass.
     * @param array<string, class-string<QueueDriverInterface>> $extraDrivers Application supplied
     *                                           drivers, keyed by the name to use in
     *                                           `queue.driver`.
     */
    public function __construct(
        public string $driver,
        public string $queue,
        public int $maxTries,
        public int $backoffBase,
        public int $backoffMax,
        public bool $backoffJitter,
        public int $leaseSeconds,
        public int $maxPayloadBytes,
        public string $table,
        public array $jobNamespaces,
        public array $extraDrivers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $raw The `queue` section of the configuration.
     */
    public static function fromArray(array $raw): self
    {
        $config = new self(
            driver: self::string($raw, 'driver', 'sync'),
            queue: self::string($raw, 'queue', 'default'),
            maxTries: self::int($raw, 'max_tries', 3, 1),
            backoffBase: self::int($raw, 'backoff_base', 5, 0),
            backoffMax: self::int($raw, 'backoff_max', 600, 0),
            backoffJitter: self::bool($raw, 'backoff_jitter', true),
            leaseSeconds: self::int($raw, 'lease_seconds', 60, 1),
            maxPayloadBytes: self::int($raw, 'max_payload_bytes', 65536, 1024),
            table: self::string($raw, 'table', 'queue_jobs'),
            jobNamespaces: self::stringList($raw, 'job_namespaces'),
            extraDrivers: self::driverMap($raw),
        );

        $config->assertConsistent();

        return $config;
    }

    /**
     * The `queue` section of the given configuration, or defaults if the file is absent.
     *
     * Absent is a real case and not an error: a project that removed the module from
     * config/app.php still has the file in the framework's own config directory, and a test that
     * boots over a temporary directory has nothing there at all.
     */
    public static function fromContainer(Config $config): self
    {
        $raw = $config->get('queue');
        /** @var array<string, mixed> $raw */
        return self::fromArray(is_array($raw) ? $raw : []);
    }

    /**
     * Whether a string may be used as a queue name.
     *
     * @param string $queue Candidate name.
     */
    public static function isValidQueueName(string $queue): bool
    {
        return preg_match(self::QUEUE_PATTERN, $queue) === 1;
    }

    /**
     * Whether a string may be used as a SQL table name.
     *
     * @param string $table Candidate name.
     */
    public static function isValidTableName(string $table): bool
    {
        return preg_match(self::TABLE_PATTERN, $table) === 1;
    }

    /**
     * The backoff curve: base, doubling, capped, optionally jittered.
     *
     * $attempts is the count including the one that just failed, so the first failure asks for
     * $backoffBase and not twice it. $jobId keys the jitter, which is why it is not optional: a
     * spread computed without one is the same for every job, and spreading identically is not
     * spreading at all.
     *
     * Deterministic jitter, keyed on the job id, because a worker that dies has to be able to
     * be reasoned about after the fact and because a test asserting a delay cannot afford to
     * sleep. The spread is a fraction of the delay rather than a percentage of it, so the
     * ceiling stays a ceiling.
     */
    public function backoffDelay(string $jobId, int $attempts): int
    {
        if ($attempts < 1) {
            $attempts = 1;
        }

        $delay = (int) min(
            (float) $this->backoffBase * (2 ** ($attempts - 1)),
            (float) $this->backoffMax
        );

        if (!$this->backoffJitter || $delay < 2) {
            return max(0, $delay);
        }

        return $delay + $this->spread($jobId, $attempts, intdiv($delay, 2));
    }

    /**
     * Stable per (job, attempt) spread. Anything derived from randomness here would make both
     * "why did every job retry at once" and "what does the test assert" unanswerable.
     */
    public function spread(string $jobId, int $attempts, int $bound): int
    {
        if ($bound < 1) {
            return 0;
        }

        return (int) (hexdec(substr(hash('sha256', $jobId . ':' . $attempts), 0, 8)) % $bound);
    }

    /**
     * Check the values that cannot be judged one at a time.
     *
     * @throws \InvalidArgumentException On the first combination that cannot mean anything.
     */
    private function assertConsistent(): void
    {
        if ($this->driver === '') {
            throw new \InvalidArgumentException('queue.driver must not be empty.');
        }

        if (!self::isValidQueueName($this->queue)) {
            throw new \InvalidArgumentException(sprintf(
                'queue.queue is "%s", which is not a legal queue name (%s).',
                $this->queue,
                self::QUEUE_PATTERN
            ));
        }

        if ($this->backoffMax < $this->backoffBase) {
            throw new \InvalidArgumentException(sprintf(
                'queue.backoff_max (%d) is below queue.backoff_base (%d), so the ceiling would '
                . 'silently be the floor.',
                $this->backoffMax,
                $this->backoffBase
            ));
        }

        if (!self::isValidTableName($this->table)) {
            throw new \InvalidArgumentException(sprintf(
                'queue.table is "%s", which is not a plain SQL identifier (%s). Table names are '
                . 'interpolated into statements and cannot be quoted into safety, so the only '
                . 'option is to refuse the name.',
                $this->table,
                self::TABLE_PATTERN
            ));
        }

    }

    // ------------------------------------------------------------------ coercion helpers

    /**
     * Read a string setting.
     *
     * @param array<string, mixed> $raw     The whole section.
     * @param string               $key      Key to read.
     * @param string               $default  Used when the key is absent.
     *
     * @throws \InvalidArgumentException When the value is present but not a string.
     */
    private static function string(array $raw, string $key, string $default): string
    {
        $value = $raw[$key] ?? $default;

        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf(
                'queue.%s must be a string, got %s.',
                $key,
                get_debug_type($value)
            ));
        }

        return $value;
    }

    /**
     * Read an integer setting.
     *
     * @param array<string, mixed> $raw    The whole section.
     * @param string               $key    Key to read.
     * @param int                  $default Used when the key is absent.
     * @param int                  $min    Smallest value that can mean anything.
     *
     * @throws \InvalidArgumentException When the value is not an integer, or is below $min.
     */
    private static function int(array $raw, string $key, int $default, int $min): int
    {
        $value = $raw[$key] ?? $default;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf(
                'queue.%s must be an integer, got %s.',
                $key,
                get_debug_type($value)
            ));
        }

        if ($value < $min) {
            throw new \InvalidArgumentException(sprintf(
                'queue.%s is %d, which is below the minimum of %d.',
                $key,
                $value,
                $min
            ));
        }

        return $value;
    }

    /**
     * Read a boolean setting.
     *
     * @param array<string, mixed> $raw     The whole section.
     * @param string               $key      Key to read.
     * @param bool                 $default  Used when the key is absent.
     *
     * @throws \InvalidArgumentException When the value is present but not a boolean.
     */
    private static function bool(array $raw, string $key, bool $default): bool
    {
        $value = $raw[$key] ?? $default;

        if (!is_bool($value)) {
            throw new \InvalidArgumentException(sprintf(
                'queue.%s must be a boolean, got %s.',
                $key,
                get_debug_type($value)
            ));
        }

        return $value;
    }

    /**
     * Read a list of strings, trimmed and de-duplicated.
     *
     * @param array<string, mixed> $raw The whole section.
     * @param string               $key Key to read.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException When the value is not a list of non-empty strings.
     */
    private static function stringList(array $raw, string $key): array
    {
        $value = $raw[$key] ?? [];

        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                'queue.%s must be an array of strings, got %s.',
                $key,
                get_debug_type($value)
            ));
        }

        $namespaces = [];

        foreach ($value as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new \InvalidArgumentException(sprintf(
                    'queue.%s must contain only non-empty strings, got %s.',
                    $key,
                    get_debug_type($entry)
                ));
            }

            $namespaces[] = trim($entry, '\\');
        }

        return array_values(array_unique($namespaces));
    }

    /**
     * Drivers the application supplies itself, so that adding one is a config change rather than
     * an edit to the module.
     *
     * Class-strings only. An instance here would be constructed before the container exists and
     * would ignore anything the module's bindings would have set up, so `queue.driver` may name
     * a class directly instead — which is the same thing with fewer ways to be wrong.
     *
     * @param array<string, mixed> $raw The whole section.
     *
     * @return array<string, class-string<QueueDriverInterface>>
     *
     * @throws \InvalidArgumentException When an entry is not a class implementing the contract.
     */
    private static function driverMap(array $raw): array
    {
        $configured = $raw['drivers'] ?? [];

        if (!is_array($configured)) {
            throw new \InvalidArgumentException(sprintf(
                'queue.drivers must be an array, got %s.',
                get_debug_type($configured)
            ));
        }

        $drivers = [];

        foreach ($configured as $name => $driver) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException('queue.drivers keys must be non-empty strings.');
            }

            if (!is_string($driver) || !class_exists($driver)) {
                throw new \InvalidArgumentException(sprintf(
                    'queue.drivers.%s must name a class that exists, got %s.',
                    $name,
                    is_string($driver) ? '"' . $driver . '"' : get_debug_type($driver)
                ));
            }

            if (!is_a($driver, QueueDriverInterface::class, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'queue.drivers.%s points at "%s", which does not implement %s.',
                    $name,
                    $driver,
                    QueueDriverInterface::class
                ));
            }

            $drivers[$name] = $driver;
        }

        return $drivers;
    }
}