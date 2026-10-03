<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Cache\FileCache;
use Phue\Core\Console\Commands\OptimizeCommand;

/**
 * `optimize` exists so the first request after a deploy is not the slow one.
 *
 * It builds a throwaway ComponentLoaderRegistry and never adds a loader to it. That is
 * not a harmless shortcut: PhueComponentLoader::buildFor() discovers a component's
 * children through the registry, and ComponentDiscoverer::scanTemplate() only accepts a
 * tag whose name the registry can resolve. With an empty registry it finds nothing, so
 * the "warmed" client module for any component that references something contains zero
 * imports — a structurally different artefact under a different hash, which no request
 * will ever hit again. The command still reported those components as warmed.
 *
 * Nothing observable distinguishes the broken warm pass from a working one on a healthy
 * project: the structures are cached either way, because the pass also loops every file
 * directly, and the compiled renders are cached either way, because a child has to be
 * compiled once regardless of who asks. That is precisely why the defect survived.
 *
 * A circular reference is the one case that separates them. Reaching it requires the
 * warm pass to resolve a child at all, so a wired registry walks into the cycle and
 * buildFor() raises CircularComponentReferenceException — reported as a failure before
 * deploy. An empty registry never resolves anything, so the cycle is never found and both
 * components are reported as warmed.
 */
#[Group('integration')]
class OptimizeCommandWarmingTest extends TestCase
{
    private string $tmpDir;
    private string $cacheDir;
    private string $viewsDir;
    private string $appDir;
    private string $phuiDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phue_optimize_' . uniqid();
        $this->cacheDir = $this->tmpDir . '/cache';
        $this->viewsDir = $this->cacheDir . '/views';
        $this->appDir = $this->tmpDir . '/app';
        $this->phuiDir = $this->tmpDir . '/phui';

        mkdir($this->viewsDir, 0777, true);
        mkdir($this->appDir, 0777, true);
        mkdir($this->phuiDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmTree($this->tmpDir);
    }

    // ------------------------------------------------- the defect this file exists for

    public function testACircularComponentReferenceIsCaughtBeforeDeploy(): void
    {
        $this->writePhue($this->appDir, 'CycleA', '<template><cycle-b /></template>');
        $this->writePhue($this->appDir, 'CycleB', '<template><cycle-a /></template>');

        [$result, $output] = $this->runOptimize();

        $this->assertMatchesRegularExpression(
            '/(Failed to warm component|circular)/i',
            $output,
            'A cycle between two components was reported as warmed. Resolving a cycle is what proves the warm pass can see the component graph at all.'
        );
        $this->assertSame(1, $result, 'A warm pass that hit a circular reference must not exit as a success.');
    }

    public function testTheWarmPassResolvesAChildHeldByTheOtherLoader(): void
    {
        // PhChild only exists in the PhUI loader, so reaching it means the registry has to
        // dispatch outside the loader that owns the parent.
        $this->writePhue($this->appDir, 'AppPage', '<template><ph-child /></template>');
        $this->writePhue($this->phuiDir, 'PhChild', '<template><span /></template>');

        [$result, $output] = $this->runOptimize();

        $this->assertSame(0, $result, "Warming failed:\n" . $output);
        $this->assertMatchesRegularExpression('/warmed \(2 components/', $output);
    }

    // ------------------------------------------------------------- guards on the pass

    public function testWarmingCoversEveryComponentInBothLoaders(): void
    {
        $this->writePhue($this->appDir, 'AppOne', '<template><span /></template>');
        $this->writePhue($this->appDir, 'AppTwo', '<template><span /></template>');
        $this->writePhue($this->phuiDir, 'PhOne', '<template><span /></template>');

        [$result, $output] = $this->runOptimize();

        $this->assertSame(0, $result, $output);
        $this->assertMatchesRegularExpression('/warmed \(3 components/', $output);
    }

    public function testACleanPassReportsTheCountItActuallyWarmed(): void
    {
        $this->writePhue($this->appDir, 'Good', '<template><span /></template>');
        $this->writePhue($this->phuiDir, 'PhChild', '<template><span /></template>');

        [$result, $output] = $this->runOptimize();

        $this->assertStringContainsString('Views cache warmed', $output);
        $this->assertMatchesRegularExpression('/\(2 components parsed & compiled\)/', $output);
        $this->assertSame(0, $result);
    }

    public function testWarmingIsIdempotent(): void
    {
        $this->writePhue($this->appDir, 'AppPage', '<template><ph-child /></template>');
        $this->writePhue($this->phuiDir, 'PhChild', '<template><span /></template>');

        [, $first] = $this->runOptimize();
        [, $second] = $this->runOptimize();

        // A warm pass that reports a different result on a second run is doing something
        // other than warming.
        $this->assertSame(
            $this->countLine($first),
            $this->countLine($second),
            'Re-running the warm pass changed its own report.'
        );
    }

    public function testWarmingPopulatesTheStructureCacheForEveryComponent(): void
    {
        $this->writePhue($this->appDir, 'AppPage', '<template><ph-child /></template>');
        $this->writePhue($this->phuiDir, 'PhChild', '<template><span /></template>');

        $this->runOptimize();
        $cache = new FileCache($this->cacheDir);

        foreach ([[$this->appDir, 'AppPage'], [$this->phuiDir, 'PhChild']] as [$dir, $name]) {
            $path = realpath($dir . '/' . $name . '.phue');

            $this->assertNotNull(
                $cache->get('phue_structure_' . md5((string) $path)),
                "Structure for {$name} was not cached."
            );
        }
    }

    // ----------------------------------------------------------------------- helpers

    /**
     * @return array{0: int, 1: string} Exit code and everything the command printed.
     */
    private function runOptimize(): array
    {
        $output = fopen('php://memory', 'r+');
        $command = new OptimizeCommand();

        $reflection = new \ReflectionMethod($command, 'optimizeViews');
        $ok = $reflection->invoke($command, $this->cacheDir, $this->viewsDir, $this->appDir, $this->phuiDir, $output);

        rewind($output);
        $printed = (string) stream_get_contents($output);
        fclose($output);

        return [$ok ? 0 : 1, $printed];
    }

    private function countLine(string $output): string
    {
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, 'Views cache warmed')) {
                return trim($line);
            }
        }

        return '';
    }

    private function writePhue(string $dir, string $name, string $template): void
    {
        file_put_contents(
            $dir . '/' . $name . '.phue',
            $template . "\n<script>export default {}</script>"
        );
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}