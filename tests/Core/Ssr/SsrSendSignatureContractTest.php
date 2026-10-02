<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Core\Ssr\SsrClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * SsrSendSignatureContractTest — every `send()` call site must name parameters that exist.
 *
 * `BunSsrEngine` dispatches through the `SsrClient` **interface**. PHP checks named
 * parameters at RUNTIME, not at dispatch time, so adding `headers:` to the call while
 * the interface still declared three parameters produced:
 *
 *     Error: Unknown named parameter $headers
 *
 * which `render()`'s `catch (Throwable)` swallowed into `return null`, and
 * `ComponentRenderer` rendered as an empty mount container. Every page lost SSR —
 * silently, with a green suite, because nothing else in the codebase ever calls
 * `send()` with a fourth argument.
 *
 * The failure had no visible symptom until a human compared a browser screenshot to
 * a curl. That is the definition of a gap a test should fill, so this one is static:
 * it reflects the declared signature and matches it against the call sites, so the
 * mismatch is caught while editing rather than in production.
 *
 * @package Phue\Tests\Core\Ssr
 */
final class SsrSendSignatureContractTest extends TestCase
{
    private const SRC = __DIR__ . '/../../../src';

    /**
     * The interfaces the framework calls BY NAMED ARGUMENT.
     *
     * Both of them have cost this pipeline an outage, for the same reason: PHP
     * checks a named argument at runtime, and both call sites sit inside a
     * `catch (Throwable)` that degrades to "no SSR" — so a renamed or missing
     * parameter does not fail, it silently empties every page's mount container.
     *
     * @return array<string, array<string, list<string>>> interface => method => parameters
     */
    private function contracts(): array
    {
        $out = [];

        foreach ([SsrClient::class => 'send', \Phue\Core\Ssr\SsrEngine::class => 'render'] as $interface => $method) {
            $reflection = new ReflectionMethod($interface, $method);
            $out[$interface][$method] = array_map(
                static fn(\ReflectionParameter $p): string => $p->getName(),
                $reflection->getParameters()
            );
        }

        return $out;
    }

    /** @return list<string> */
    private function declaredParameters(): array
    {
        return $this->contracts()[SsrClient::class]['send'];
    }

    /**
     * Every named argument used in a `->send(...)` call, plus the total argument
     * count (named arguments included — a named argument still occupies one slot).
     *
     * @return list<array{file: string, line: int, names: list<string>, arity: int}>
     */
    private function callSites(string $method = 'send', array $onlyIn = []): array
    {
        $sites = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SRC, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $path = str_replace('\\', '/', $file->getPathname());

            if ($onlyIn !== [] && !array_intersect($onlyIn, [str_replace('\\', '/', $file->getPathname())])) {
                continue;
            }

            if (!preg_match_all('/->' . preg_quote($method, '/') . '\((.*?)\)\s*;/s', $source, $calls, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($calls[0] as $index => $call) {
                $arguments = $calls[1][$index][0];
                $line = substr_count(substr($source, 0, $calls[0][$index][1]), "\n") + 1;

                preg_match_all('/([a-zA-Z_]\w*)\s*:\s*[^,]+/', $arguments, $named);

                // Total argument count: top-level commas plus a trailing one. A
                // NAMED argument occupies a slot too, so it is counted here once and
                // must not be added again from $named — doing that reported a
                // four-argument call site as passing eight arguments.
                $arity = 0;
                $depth = 0;
                $token = '';
                $sawToken = false;

                foreach (str_split($arguments) as $char) {
                    if ($char === '(' || $char === '[') {
                        $depth++;
                    } elseif ($char === ')' || $char === ']') {
                        $depth--;
                    }

                    if ($char === ',' && $depth === 0) {
                        if (trim($token) !== '') {
                            $arity++;
                        }
                        $token = '';
                        $sawToken = false;
                        continue;
                    }

                    $token .= $char;
                    $sawToken = true;
                }

                if ($sawToken && trim($token) !== '') {
                    $arity++;
                }

                $sites[] = [
                    'file' => $path,
                    'line' => $line,
                    'names' => $named[1],
                    'arity' => $arity,
                ];
            }
        }

        return $sites;
    }

    public function testTheSignatureContractIsNotEmpty(): void
    {
        // A green run over zero call sites is not a pass: a moved directory or a
        // refactored call name would silently disable the whole check.
        $sites = $this->callSites();

        self::assertNotSame(
            [],
            array_values(array_filter(
                $sites,
                static fn(array $s): bool => str_contains($s['file'], '/Ssr/')
            )),
            'the SSR send() call sites were not found — the scan is looking in the wrong place'
        );
    }

    public function testEveryNamedArgumentExistsOnTheInterface(): void
    {
        $declared = $this->declaredParameters();
        $offenders = [];

        foreach ($this->callSites() as $site) {
            foreach ($site['names'] as $name) {
                if (in_array($name, $declared, true)) {
                    continue;
                }

                $offenders[] = sprintf(
                    '%s:%d — `send(%s: …)` but SsrClient::send() declares only (%s)',
                    str_replace(self::SRC . '/', '', $site['file']),
                    $site['line'],
                    $name,
                    implode(', ', $declared)
                );
            }
        }

        self::assertSame([], $offenders, implode("\n  ", [
            'These call sites pass a named argument the interface does not declare.',
            'PHP only rejects this at RUNTIME, and render() catches Throwable into a',
            'null return — so the page silently loses SSR instead of failing loudly.',
            '',
            implode("\n  ", $offenders),
        ]));
    }

    public function testNoCallSitePassesMoreArgumentsThanTheInterfaceAccepts(): void
    {
        $declared = $this->declaredParameters();
        $maximum = count($declared);
        $offenders = [];

        foreach ($this->callSites() as $site) {
            $total = $site['arity'];

            if ($total > $maximum) {
                $offenders[] = sprintf(
                    '%s:%d — %d argument(s) to send(), which takes %d',
                    str_replace(self::SRC . '/', '', $site['file']),
                    $site['line'],
                    $total,
                    $maximum
                );
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    public function testEveryNamedRenderArgumentExistsOnTheEngineInterface(): void
    {
        // `ComponentRenderer` calls `$this->ssrEngine->render(template:, state:, context:)`.
        // An implementation whose parameter is called `$t` throws
        // `Error: Unknown named parameter $template`, which the renderer's own
        // catch turns into an empty mount container — the failure mode this whole
        // file exists to prevent, one interface over.
        $declared = $this->contracts()[\Phue\Core\Ssr\SsrEngine::class]['render'];
        $offenders = [];

        foreach ($this->callSites('render', ['src/Core/UI/ComponentRenderer.php']) as $site) {
            foreach ($site['names'] as $name) {
                if (in_array($name, $declared, true)) {
                    continue;
                }

                $offenders[] = sprintf(
                    '%s:%d — `render(%s: …)` but SsrEngine::render() declares only (%s)',
                    str_replace(self::SRC . '/', '', $site['file']),
                    $site['line'],
                    $name,
                    implode(', ', $declared)
                );
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    public function testEveryImplementationCarriesTheSameSignature(): void
    {
        // An implementation may widen the signature but must not narrow it: an
        // engine that passes `headers:` needs every implementation to accept it, or
        // the failure returns the next time a double is swapped in.
        $declared = $this->declaredParameters();

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../..', \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (!str_contains($source, 'implements SsrClient')) {
                continue;
            }

            if (preg_match('/function\s+send\s*\(([^)]*)\)/', $source, $matches) !== 1) {
                continue;
            }

            preg_match_all('/\$(\w+)/', $matches[1], $found);
            $names = $found[1];

            foreach ($declared as $parameter) {
                if (!in_array($parameter, $names, true)) {
                    $offenders[] = sprintf(
                        '%s — SsrClient::send() declares $%s, this implementation does not',
                        str_replace(__DIR__ . '/../../..', '', $file->getPathname()),
                        $parameter
                    );
                }
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }
}