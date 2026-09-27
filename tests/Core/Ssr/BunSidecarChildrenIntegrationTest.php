<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Core\Config;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Phue\Tests\Support\TestComponentLoader;

/**
 * BunSidecarChildrenIntegrationTest — end-to-end regression guard for the
 * DocSection/PhNavigation SSR crash: a component module that declares child
 * components via `components: { ... }` MUST be importable by the sidecar.
 *
 * Background: buildForSsr() historically emitted `components: { PhBadge,
 * PhHeading }` (or `...(opts.components || {}), PhTheme`) without importing
 * or declaring those identifiers inside the module. The sidecar loads every
 * envelope module via a real Bun dynamic import() (ssr/server.ts
 * loadModuleFromSource); the missing bindings therefore threw
 * `ReferenceError: PhBadge is not defined`, the render returned COMPILE_ERROR
 * and the PHP engine fail-opened to the raw <ph-...> template (hydration
 * mismatch on /docs).
 *
 * Unlike the unit contract test, this verifies the FULL loop against the real
 * Bun runtime: build envelope → socket → V8 → html out.
 *
 * @package Phue\Tests\Core\Ssr
 */
#[Group('integration')]
#[Group('ssr')]
final class BunSidecarChildrenIntegrationTest extends TestCase
{
    private string $baseDir;
    private PhueComponentLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::sidecarReachable()) {
            self::markTestSkipped(sprintf('Bun sidecar is not reachable on "%s".', self::socketPath()));
        }

        $this->baseDir = sys_get_temp_dir() . '/phue_ssr_children_' . uniqid('', true);
        mkdir($this->baseDir, 0777, true);

        $this->loader = TestComponentLoader::make($this->baseDir);
        $registry = new ComponentLoaderRegistry();
        $this->loader->setRegistry($registry);
        $registry->addLoader($this->loader);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->baseDir);

        parent::tearDown();
    }

    public function testComponentWithChildrenRendersThroughSidecar(): void
    {
        // DocSection reproduction: scriptless parent referencing two children.
        $this->write('PhBadge', <<<'PHUE'
<template>
<span class="badge">{{ label }}</span>
</template>

<script>
export default { props: { label: { type: String, default: '' } } };
</script>
PHUE);
        $this->write('PhHeading', <<<'PHUE'
<template>
<h2><slot/></h2>
</template>
PHUE);
        $this->write('PhSection', <<<'PHUE'
<template>
<section class="doc-section">
  <ph-badge label="SSR"></ph-badge>
  <ph-heading>Rendered title</ph-heading>
</section>
</template>
PHUE);

        $response = $this->request('POST', '/v1/render', [
            'template'   => '<div id="app"><ph-section></ph-section></div>',
            'data'       => ['placeholder' => 'Docs'],
            'components' => [
                'PhSection' => $this->ssrModule('PhSection'),
                'PhBadge'   => $this->ssrModule('PhBadge'),
                'PhHeading' => $this->ssrModule('PhHeading'),
            ],
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertTrue(
            $response['json']['ok'],
            'Sidecar must import and render the parent-with-children envelope. '
            . 'COMPILE_ERROR here means buildForSsr emitted an unbounded child '
            . 'identifier (ReferenceError on import). Error: '
            . ($response['json']['error']['message'] ?? 'n/a')
        );

        // The whole point of SSR: the resolved components' markup is in the
        // Html, NOT the raw <ph-...> source template.
        $html = (string) $response['json']['html'];
        $this->assertStringContainsString('class="badge"', $html);
        $this->assertStringContainsString('<h2>', $html, 'Slot content renders; Vue SSR may add comment markers around it.');
        $this->assertStringContainsString('Rendered title', $html);
        $this->assertStringNotContainsString('<ph-section', $html);
        $this->assertStringNotContainsString('<ph-badge', $html);
    }

    public function testCreateAppProxyModuleWithChildrenImportsCleanly(): void
    {
        // PhNavigation shape: scripted with `createSSRApp`, child (PhTheme) via
        // the `...(opts.components || {}), PhTheme` proxy branch.
        $this->write('PhTheme', <<<'PHUE'
<template>
<button class="theme-toggle" type="button">{{ label }}</button>
</template>

<script>
export default { props: { label: { type: String, default: 'Toggle' } } };
</script>
PHUE);
        $this->write('PhNavigation', <<<'PHUE'
<template>
<div class="nav"><ph-theme aria-label="Toggle theme"></ph-theme></div>
</template>

<script>
import { createSSRApp } from 'vue';
export default { setup() { return {}; } };
</script>
PHUE);

        $response = $this->request('POST', '/v1/render', [
            'template'   => '<div id="app"><ph-navigation></ph-navigation></div>',
            'data'       => [],
            'components' => [
                'PhNavigation' => $this->ssrModule('PhNavigation'),
                'PhTheme'      => $this->ssrModule('PhTheme'),
            ],
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertTrue(
            $response['json']['ok'],
            'createSSRApp-proxy parent referencing a child must not throw '
            . 'ReferenceError inside the sidecar. Error: '
            . ($response['json']['error']['message'] ?? 'n/a')
        );

        $html = (string) $response['json']['html'];
        $this->assertStringContainsString('class="theme-toggle"', $html);
        $this->assertStringNotContainsString('<ph-theme', $html);
    }

    // ------------------------------------------------------------ helpers

    private function write(string $name, string $content): void
    {
        file_put_contents($this->baseDir . '/' . $name . '.phue', $content);
    }

    private function ssrModule(string $name): string
    {
        $module = $this->loader->buildForSsr($name);

        if ($module === null || trim($module) === '') {
            self::fail("SSR module not available for {$name}.");
        }

        return $module;
    }

    private static function socketPath(): string
    {
        $config = new Config('/app/config');

        return (string) $config->get('ssr.socket', "\0phue-ssr");
    }

    private static function sidecarReachable(): bool
    {
        $client = @stream_socket_client('unix://' . self::socketPath(), $errno, $errstr, 0.5);

        if ($client !== false) {
            fclose($client);

            return true;
        }

        return false;
    }

    /**
     * Sends one raw HTTP/1.1 request over the unix socket and parses the
     * Contract 3.2 response envelope.
     *
     * @return array{status: int, json: array<string, mixed>}
     */
    private function request(string $method, string $path, array $body): array
    {
        /* @var resource|false $socket */
        $socket = @stream_socket_client('unix://' . self::socketPath(), $errno, $errstr, 2.0);

        if ($socket === false) {
            throw new RuntimeException("Cannot connect to Bun sidecar: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 5);

        $rawBody = json_encode($body, JSON_THROW_ON_ERROR);

        $request = sprintf(
            "%s %s HTTP/1.1\r\nHost: phue-ssr\r\nConnection: close\r\n",
            $method,
            $path
        );

        if ($rawBody !== null) {
            $request .= "Content-Type: application/json\r\nContent-Length: " . strlen($rawBody) . "\r\n";
        }

        $request .= "\r\n" . ($rawBody ?? '');

        fwrite($socket, $request);

        $response = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
        }

        fclose($socket);

        return $this->parse($response);
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     */
    private function parse(string $response): array
    {
        $position = strpos($response, "\r\n\r\n");

        $head = $position === false ? $response : substr($response, 0, $position);
        $body = $position === false ? '' : substr($response, $position + 4);

        if (!preg_match('#^HTTP/1\.1 (\d{3})#', $head, $matches)) {
            throw new RuntimeException("Malformed HTTP response head: {$head}");
        }

        $json = json_decode($body, true);

        if (!is_array($json)) {
            throw new RuntimeException("Malformed JSON envelope: {$body}");
        }

        return [
            'status' => (int) $matches[1],
            'json'   => $json,
        ];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}