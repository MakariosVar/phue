<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Hiit\Hiit;
use Phue\Core\Response;

/**
 * Tells a browser which components changed since it last asked.
 *
 * The endpoint answers with JSON and returns. That is the whole design, and it is the reason
 * it is safe: every request costs one worker for the length of one directory scan, and then the
 * worker is free. A tab left open over lunch holds nothing.
 *
 * It exists because the obvious alternative does not work here. A Server-Sent Events stream is
 * a request that never finishes, so it occupies its worker for as long as the tab is open — and
 * Swoole offers no way to notice the tab closing: not write(), not isWritable(), not
 * exists(), not connection_aborted(), because the chunks are small enough that the kernel send
 * buffer never reports a broken pipe. Four open tabs took the whole dev server down. Four
 * requests a second do not.
 *
 * The state lives here rather than in the browser because "changed since when" is the only
 * question worth asking, and the server is the only party that knows the previous answer. A
 * client sending the last set it saw would let a stale client talk the server into reporting
 * every component as new.
 *
 * @package Phue\Modules\DevTool
 */
final class LiveReloadRoute
{
    /**
     * Where the endpoint lives.
     *
     * Owned here rather than repeated in the module that registers it and the toolbar that polls
     * it, because two spellings of one path is a 404 that only shows up in development.
     */
    public const ENDPOINT = '/_phue/live-reload';

    /**
     * One detector per component directory, built on the first request.
     *
     * @var null|list<Hiit>
     */
    private ?array $hiits = null;

    /**
     * What each detector reported last time.
     *
     * @var list<array<string, string>>
     */
    private array $known = [];

    public function __construct(
        private readonly ComponentLoaderRegistry $registry,
    ) {
    }

    /**
     * @return Response
     */
    public function __invoke(): Response
    {
        $this->watch();

        $changed = [];

        foreach ($this->hiits ?? [] as $index => $hiit) {
            foreach ($hiit->changed($this->known[$index]) as $name) {
                $changed[] = $name;
            }

            // The snapshot the comparison just made, so a poll costs one scan rather than two.
            $this->known[$index] = $hiit->snapshot();
        }

        $changed = array_values(array_unique($changed));
        sort($changed);

        // Without this a browser or an intermediary is free to serve the answer to the next
        // poll from its own cache, and the page stops noticing changes that never happened.
        return Response::json(['changed' => $changed])
            ->setHeader('Cache-Control', 'no-store, must-revalidate');
    }

    /**
     * The directories worth watching, discovered from the loaders actually registered.
     *
     * Deduplicated by realpath, because two loaders can legitimately point at one directory and
     * scanning it twice would double the cost of every poll for no extra coverage.
     *
     * @return void
     */
    private function watch(): void
    {
        if ($this->hiits !== null) {
            return;
        }

        $directories = [];

        foreach ($this->registry->resolvedLoaders() as $loader) {
            $real = realpath($loader->componentsDir());

            if ($real !== false) {
                $directories[$real] = true;
            }
        }

        $directories = array_keys($directories);
        sort($directories);

        $this->hiits = [];

        foreach ($directories as $directory) {
            $hiit = new Hiit($directory);

            $this->hiits[] = $hiit;

            // Primed here, not by discarding the first answer. A comparison against an empty
            // baseline calls every component on disk new, so the first poll would report the
            // entire project and the page would reload for nothing.
            $this->known[] = $hiit->currentHashes();
        }
    }
}