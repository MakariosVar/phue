<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueTemplateParser;
use Phue\Core\UI\TemplateCompilerBridge;
use Psr\SimpleCache\CacheInterface;

/**
 * Deterministic, socket-free compiler bridge for unit tests.
 *
 * PhueComponentLoader requires a TemplateCompilerBridge (it throws a
 * LogicException otherwise), but the vast majority of the suite never needs a
 * real Vue compile: it only needs the loader to produce a *stable* module
 * string so that shaping, hashing and asset-URL logic can be asserted.
 *
 * This double is therefore content-addressed — identical (template, ssr) pairs
 * always yield identical output, different pairs always differ. That property
 * is what makes hash-based assertions (moduleHash, asset URLs) meaningful
 * without a socket.
 *
 * For tests that genuinely need real @vue/compiler output, use
 * TestCompilerBridge::live() instead.
 */
final class TestCompilerBridge extends TemplateCompilerBridge
{
    /** @var array<int, array{template: string, ssr: bool}> */
    private array $calls = [];

    private int $failNext = 0;

    public function __construct(string $socketPath = '/dev/null')
    {
        parent::__construct($socketPath, null);
    }

    /**
     * Deterministic stand-in for @vue/compiler-dom / @vue/compiler-ssr.
     *
     * Emits both `render` and `ssrRender` bindings regardless of the requested
     * mode, because PhueComponentLoader::buildFor() exports __phueRender from
     * `render` while buildForSsr() exports __phueSsrRender from `ssrRender`,
     * and a component may be built through either path.
     */
    public function compile(string $template, bool $ssr = false): string
    {
        $this->calls[] = ['template' => $template, 'ssr' => $ssr];

        if ($this->failNext > 0) {
            $this->failNext--;

            throw new \RuntimeException('TestCompilerBridge: simulated sidecar outage.');
        }

        $digest = substr(sha1($template . '|' . ($ssr ? 'ssr' : 'dom')), 0, 12);

        return "// phue-test-compile:{$digest}\n"
            . "export function render(_ctx, _cache) { return null; }\n"
            . "export function ssrRender(_ctx, _push, _parent, _attrs) { _push(`<div>{$digest}</div>`); }\n";
    }

    public function compileCount(): int
    {
        return count($this->calls);
    }

    /**
     * @return array<int, array{template: string, ssr: bool}>
     */
    public function recordedCalls(): array
    {
        return $this->calls;
    }

    public function resetCounters(): void
    {
        $this->calls = [];
    }

    /**
     * Makes the next $n compile() calls throw, emulating an unreachable sidecar.
     */
    public function failNextCalls(int $n): void
    {
        $this->failNext = max(0, $n);
    }

    /**
     * A bridge that performs a real compile against a live Bun sidecar.
     *
     * Callers are expected to skip when the socket is down (see the
     * #[Group('ssr')] convention used by BunSidecarIntegrationTest).
     */
    public static function live(?CacheInterface $cache = null): TemplateCompilerBridge
    {
        $socket = (string) (getenv('SSR_SOCKET') ?: '/tmp/phue-ssr.sock');

        return new TemplateCompilerBridge($socket, $cache);
    }
}
