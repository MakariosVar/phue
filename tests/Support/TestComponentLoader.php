<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueTemplateParser;
use Phue\Core\UI\TemplateCompilerBridge;
use Psr\SimpleCache\CacheInterface;

/**
 * Convenience factory for PhueComponentLoader instances under test.
 *
 * Keeps the mandatory compilerBridge argument out of every test file while
 * still making the dependency explicit at the point of construction.
 */
final class TestComponentLoader
{
    private static ?bool $sidecarUp = null;

    /**
     * Whether a Bun SSR sidecar is reachable on the configured socket.
     *
     * Probed once per process — the answer cannot change meaningfully within a
     * single PHPUnit run, and probing per-call would add a socket round-trip
     * to every single loader construction.
     */
    public static function sidecarReachable(): bool
    {
        if (self::$sidecarUp !== null) {
            return self::$sidecarUp;
        }

        $socket = (string) (getenv('SSR_SOCKET') ?: '/tmp/phue-ssr.sock');

        if (!file_exists($socket)) {
            return self::$sidecarUp = false;
        }

        $client = new \Phue\Core\Ssr\UnixSsrClient(
            socket: $socket,
            connectTimeoutMs: 500,
            timeoutMs: 2000
        );

        return self::$sidecarUp = $client->isAvailable();
    }

    /**
     * Resets the memoized sidecar probe. Used by tests that deliberately
     * simulate an unreachable sidecar.
     */
    public static function forgetSidecarState(): void
    {
        self::$sidecarUp = null;
    }

    /**
     * The bridge a loader gets when the test does not supply one.
     *
     * A real @vue/compiler compile is required by any test that inspects the
     * generated module body or executes it in Bun — a stub cannot satisfy
     * those. When no sidecar is listening we degrade to the deterministic stub
     * so shape-only assertions still work, at the cost of skipping the
     * content-dependent ones (which already carry a sidecar-skip guard).
     */
    public static function defaultBridge(): TemplateCompilerBridge
    {
        return self::sidecarReachable()
            ? TestCompilerBridge::live()
            : new TestCompilerBridge();
    }

    public static function make(
        string $componentsDir,
        ?PhueTemplateParser $parser = null,
        ?CacheInterface $persistentCache = null,
        ?string $viewsCacheDir = null,
        ?TemplateCompilerBridge $compilerBridge = null
    ): PhueComponentLoader {
        return new PhueComponentLoader(
            componentsDir: $componentsDir,
            parser: $parser,
            persistentCache: $persistentCache,
            viewsCacheDir: $viewsCacheDir,
            compilerBridge: $compilerBridge ?? self::defaultBridge(),
        );
    }

    /**
     * Builds a module string for a bare template/script pair through the real
     * loader pipeline (structure parse -> compile -> module shaping).
     *
     * Replaces the removed PhueComponentLoader::buildModule() static helper:
     * a temporary single-component directory is created, the module is built
     * by an actual loader instance, and the directory is torn down. Tests use
     * this to compute the *expected* module for hash / asset-URL assertions,
     * so both sides of a comparison go through identical code.
     *
     * @param bool $ssr Build the SSR module (buildForSsr) instead of the client one.
     */
    public static function moduleFor(
        string $template,
        ?string $script = null,
        bool $ssr = false,
        ?TemplateCompilerBridge $bridge = null
    ): string {
        $dir = sys_get_temp_dir() . '/phue-expected-' . bin2hex(random_bytes(6));

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $sfc = "<template>\n" . $template . "\n</template>\n";

        if ($script !== null && trim($script) !== '') {
            $sfc .= "\n<script>\n" . $script . "\n</script>\n";
        }

        file_put_contents($dir . '/Expected.phue', $sfc);

        try {
            $loader = self::make($dir, compilerBridge: $bridge);

            $module = $ssr ? $loader->buildForSsr('Expected') : $loader->buildFor('Expected');

            if ($module === null) {
                throw new \RuntimeException('TestComponentLoader::moduleFor() produced no module.');
            }

            return $module;
        } finally {
            @unlink($dir . '/Expected.phue');
            @rmdir($dir);
        }
    }
}
