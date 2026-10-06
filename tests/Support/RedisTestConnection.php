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
        [$host, $port] = self::endpoint();

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
     * A second connection to the same server, for a process that may not touch the first one.
     *
     * What a test needs when it makes another process use the bus: a socket belongs to the process
     * that opened it, and a child that inherited one cannot both keep it and be trusted with it.
     *
     * @return \Redis
     */
    public function client(): \Redis
    {
        [$host, $port] = self::endpoint();

        $redis = new \Redis();

        if ($redis->connect($host, $port, 0.35) !== true) {
            throw new \RuntimeException(sprintf('Could not connect to Redis at %s:%d.', $host, $port));
        }

        $redis->setOption(\Redis::OPT_READ_TIMEOUT, 2.0);

        return $redis;
    }

    /**
     * Where to look for Redis, the same way for every connection this harness makes.
     *
     * The host defaults to the loopback address rather than the `redis` service name, because the
     * app container runs on the host network and a compose hostname does not resolve there.
     *
     * Public because a test that boots the framework in a child process has to write that same
     * address into the child's configuration, and two places that read the environment differently
     * is how a suite ends up passing against a Redis the application never sees.
     *
     * @return array{0: string, 1: int}
     */
    public static function endpoint(): array
    {
        return [
            (string) (getenv('PHUE_TEST_REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('PHUE_TEST_REDIS_PORT') ?: 6379),
        ];
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
        $this->remove($this->keys());
    }

    /**
     * Remove a bus's own keys, given the namespace that bus was built with.
     *
     * For a test that boots the framework in another process: that process builds its bus from the
     * application's configuration, so its namespace is the application's — `phue:ws:<endpoint>` — and
     * not this harness's. Enumerated rather than discovered, for the same reason as {@see keys()}.
     *
     * @return void
     */
    public function cleanupFor(string $namespace): void
    {
        $this->remove([$namespace . ':stream', $namespace . ':cursor']);
    }

    /**
     * @param list<string> $keys
     */
    private function remove(array $keys): void
    {
        foreach ($keys as $key) {
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