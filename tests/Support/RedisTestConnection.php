<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

/**
 * A Redis connection for tests, on keys nobody else owns.
 *
 * Two rules, both learned the hard way:
 *
 * <b>Never touch the database as a whole.</b> `FLUSHDB`, `FLUSHALL` and `KEYS *` are not available
 * here. The Redis instance this suite runs against is the one the development stack already has
 * other things on — and a test that clears it takes the developer's own data with it. Every key
 * this harness writes lives under a namespace unique to one test run, and teardown deletes exactly
 * the keys it created and nothing else. A leaked key is a nuisance; a deleted one is someone's data.
 *
 * <b>Absence is not a failure.</b> Redis is a development dependency, not a requirement, so a
 * suite that hard-failed without it would report the project's health as broken on a machine that
 * simply has no Redis running. {@see available()} returns null instead, and the tests skip.
 */
final class RedisTestConnection
{
    private function __construct(
        public readonly \Redis $redis,
        public readonly string $namespace
    ) {
    }

    /**
     * A connected harness, or null when there is no Redis to talk to.
     *
     * The host defaults to the loopback address rather than the `redis` service name, because the
     * app container runs on the host network and a compose hostname does not resolve there. Both are
     * overridable so the same suite works against a local daemon or a CI service.
     */
    public static function available(): ?self
    {
        $host = (string) (getenv('PHUE_TEST_REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('PHUE_TEST_REDIS_PORT') ?: 6379);

        $redis = new \Redis();

        try {
            if (@$redis->connect($host, $port, 0.35) !== true) {
                return null;
            }

            if ($redis->ping() !== true) {
                return null;
            }

            // Long enough for a blocking read. Left at the default it is smaller than a BLOCK and
            // the read fails with a connection error instead of a timeout, which looks like Redis
            // being broken rather than like a timeout that was asked for.
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, 2.0);
        } catch (\Throwable) {
            return null;
        }

        // Unique per process *and* per call, so two tests running in the same worker, or a rerun
        // after a crash, cannot land on each other's keys.
        return new self($redis, 'phue:test:ws:' . bin2hex(random_bytes(8)));
    }

    /**
     * Every key this harness may have created, and may therefore delete.
     *
     * Enumerated rather than discovered. A prefix scan would need `KEYS`, which is both a blocking
     * call on a live server and exactly the operation that turns a test suite into a hazard.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return [
            $this->namespace . ':stream',
            $this->namespace . ':cursor',
        ];
    }

    /**
     * Remove this harness's keys, and only those.
     */
    public function cleanup(): void
    {
        foreach ($this->keys() as $key) {
            try {
                $this->redis->del($key);
            } catch (\Throwable) {
                // A Redis that has gone away has nothing left to clean up.
            }
        }
    }

    /**
     * Whether a key exists, so a test can assert that cleanup actually happened.
     */
    public function has(string $suffix): bool
    {
        return (bool) $this->redis->exists($this->namespace . ':' . $suffix);
    }
}