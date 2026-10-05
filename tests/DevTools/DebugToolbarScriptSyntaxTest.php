<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use Phue\Modules\DevTool\DebugToolbar;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;
use PHPUnit\Framework\TestCase;

/**
 * The toolbar ships JavaScript inside the HTML it renders, so every change to it is a chance to
 * emit a page that does not parse. A syntax error there is invisible to the server: the request
 * succeeds, the response is 200, and the browser throws before the first statement runs. Every
 * feature of the toolbar dies at once and nothing in the logs says why.
 *
 * So the script is handed to a real JavaScript parser. A failing parse is a test failure here,
 * which is a much cheaper place to find out than a developer wondering why live reload stopped.
 */
final class DebugToolbarScriptSyntaxTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        $this->temporaryFiles = [];
    }

    public function testTheEmittedToolbarScriptParses(): void
    {
        $html = (new DebugToolbar())->render(new RequestProfiler(new Stopwatch()), 1.0);

        $scripts = $this->extractInlineScripts($html);
        $this->assertNotEmpty($scripts, 'the toolbar is expected to ship an inline script');

        foreach ($scripts as $index => $script) {
            $this->assertScriptParses($script, (string) $index);
        }
    }

    /**
     * The feature flag paths reach different code, so every one of them has to parse. A test that
     * only covers the default would happily keep an unquoted string in the polling branch.
     */
    public function testTheEmittedScriptParsesWithEveryLiveReloadSetting(): void
    {
        $toolbar = new DebugToolbar();

        $settings = [
            'default' => [],
            'polling' => ['live_reload_interval_ms' => 1000],
            'standby' => ['live_reload_interval_ms' => 0],
            'nonsense' => ['live_reload_interval_ms' => -1]
        ];

        foreach ($settings as $label => $options) {
            $html = $toolbar->render(new RequestProfiler(new Stopwatch()), 1.0, [], $options);

            foreach ($this->extractInlineScripts($html) as $index => $script) {
                $this->assertScriptParses($script, $label . '/' . $index);
            }
        }
    }

    /**
     * The interpolations are the whole risk of this file: a PHP string dropped into JavaScript
     * without quoting is valid PHP and a syntax error in the browser. The topic, the endpoint and
     * the payload must all reach the script as string literals.
     */
    public function testEveryInterpolatedValueIsAQuotedLiteral(): void
    {
        $html = (new DebugToolbar())->render(new RequestProfiler(new Stopwatch()), 1.0);

        foreach ($this->extractInlineScripts($html) as $script) {
            // A bare identifier where a string belongs, such as `var topic = _phue:components;`.
            $this->assertDoesNotMatchRegularExpression(
                '/=\s*[A-Za-z_][A-Za-z0-9_]*\s*:/',
                $script,
                'an unquoted interpolation leaked a PHP string into the script as JavaScript'
            );
        }

        $this->assertStringContainsString('var topic = "_phue:components"', $html);
    }

    /**
     * The regression that made live reload silently dead.
     *
     * A Swoole server completes a WebSocket handshake on its own, so an open socket proves
     * nothing about whether the module is wired. Treating "open" as "the channel works" meant a
     * server with the module configured but switched off — which accepts the connection and then
     * says nothing — silenced the polling fallback exactly when it was needed.
     */
    public function testTheRealtimeChannelOnlyCountsOnceItHasDeliveredSomething(): void
    {
        $script = $this->liveReloadScript();

        $onOpen = strpos($script, 'socket.onopen');
        $onMessage = strpos($script, 'socket.onmessage');

        $this->assertNotFalse($onOpen);
        $this->assertNotFalse($onMessage);

        // The flag must not be set in the open handler, and must be set when a message lands.
        $openHandler = $this->withoutComments(substr($script, $onOpen, $onMessage - $onOpen));
        $this->assertStringNotContainsString(
            'pushed = true',
            $openHandler,
            'an open socket proves only that the handshake completed'
        );

        $messageHandler = $this->withoutComments(substr($script, $onMessage, 700));
        $this->assertStringContainsString(
            'pushed = true',
            $messageHandler,
            'a delivered message is the only proof the channel works'
        );
    }

    /**
     * The script with its comments removed.
     *
     * The comments in this file explain exactly why the flag must not be set on open, and they
     * name the flag while doing it — so an assertion over the raw text fails on the explanation
     * rather than on the code. A test that cannot tell prose from behaviour is a test that gets
     * "fixed" by deleting the comment.
     */
    private function withoutComments(string $script): string
    {
        return (string) preg_replace('#^\s*//.*$#m', '', $script);
    }

    private function liveReloadScript(): string
    {
        $html = (new \Phue\Modules\DevTool\DebugToolbar())->render(
            new \Phue\Modules\DevTool\RequestProfiler(new \Phue\Modules\DevTool\Stopwatch()),
            1.0
        );

        foreach ($this->extractInlineScripts($html) as $script) {
            if (str_contains($script, 'socket.onopen')) {
                return $script;
            }
        }

        $this->fail('the toolbar did not emit a realtime client');
    }

    /**
     * @return list<string>
     */
    private function extractInlineScripts(string $html): array
    {
        if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $matches) === false) {
            return [];
        }

        return array_values(
            array_filter(
                array_map('trim', $matches[1]),
                static fn (string $script): bool => $script !== ''
            )
        );
    }

    private function assertScriptParses(string $script, int|string $label): void
    {
        $bun = $this->findBun();

        if ($bun === null) {
            $this->markTestSkipped('no JavaScript parser available in this environment');
        }

        $file = tempnam(sys_get_temp_dir(), 'phue_dt_script_') . '.js';
        file_put_contents($file, $script);
        $this->temporaryFiles[] = $file;

        // `bun build` parses and bundles without executing, which is exactly what is wanted here:
        // running the toolbar's script in a test would try to touch `window`.
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [$bun, 'build', '--target=browser', $file, '--outfile', $file . '.out.js'],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            $this->markTestSkipped('could not start the JavaScript parser');
        }

        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0) {
            $this->fail(
                sprintf(
                    "the toolbar script does not parse (%s):\n%s\n--- script ---\n%s",
                    $label,
                    trim($stderr),
                    $this->excerpt($script)
                )
            );
        }

        $this->addToAssertionCount(1);
    }

    private function findBun(): ?string
    {
        foreach (['bun', 'node', 'deno'] as $candidate) {
            $path = trim((string) @shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($candidate))));

            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function excerpt(string $script): string
    {
        $lines = explode("\n", $script);
        $number = 0;

        foreach ($lines as $line) {
            $number++;

            if (str_contains($line, 'SyntaxError') || str_contains($line, 'Unexpected')) {
                break;
            }
        }

        return implode(
            "\n",
            array_slice($lines, max(0, $number - 4), 8, true)
        );
    }
}
