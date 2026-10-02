<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\UI\PhueTemplateParser;

/**
 * PhuiScriptSyntaxTest — every Phui `<script>` block must be parseable JavaScript.
 *
 * There is no build step and no bundler in this kit, which means nothing checks
 * the script of a component before it ships. `PhueComponentLoader::buildFor`
 * concatenates the compiled template with the script and returns a string: it
 * compiles the TEMPLATE and never looks at the script. So a component whose
 * script is syntactically broken produces a module that the loader happily serves
 * and the browser refuses.
 *
 * This is not hypothetical. `PhCarousel` shipped for the length of a test run
 * with `type Number,` where `type: Number,` was meant — one dropped character,
 * and the whole component's module was unparseable. Every other gate passed:
 * the module built, the classes resolved, the props were "declared", the
 * accessibility contract had nothing to say about it. What caught it was a test
 * that actually executed the module.
 *
 * So: parse every script, with a real parser, in one pass.
 *
 * Requirements (skipped, never failed, when absent): `bun` on PATH.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
final class PhuiScriptSyntaxTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    private static ?string $skipReason = null;

    /** @var array<string, string> component name => parse error */
    private static array $broken = [];

    private static bool $ran = false;

    private static function scan(): void
    {
        if (self::$ran) {
            return;
        }
        self::$ran = true;

        $bun = trim((string) shell_exec('command -v bun 2>/dev/null'));
        if ($bun === '') {
            self::$skipReason = 'bun is unavailable; cannot parse component scripts.';

            return;
        }

        $parser = new PhueTemplateParser();
        $work = sys_get_temp_dir() . '/phue-syntax-' . bin2hex(random_bytes(6));
        mkdir($work, 0777, true);

        try {
            // Every script in one file, each wrapped so a parse failure names the
            // component instead of aborting the batch.
            $bundle = '';
            foreach (glob(self::PHUI_DIR . '/*.phue') ?: [] as $file) {
                $name = basename($file, '.phue');
                $script = $parser->parseStructure($file)['script'] ?? null;
                if ($script === null) {
                    continue;
                }

                $bundle .= "globalThis.__files['{$name}'] = " . json_encode(trim($script)) . ";\n";
            }

            $harness = <<<'JS'
globalThis.__files = {};
JS;
            $harness .= $bundle . <<<'JS'
const transpiler = new Bun.Transpiler({ loader: 'js' });
const broken = {};
for (const name of Object.keys(globalThis.__files)) {
  try {
    transpiler.scan(globalThis.__files[name]);
  } catch (error) {
    broken[name] = String((error && error.message) || error);
  }
}
console.log(JSON.stringify({ broken }));
JS;

            file_put_contents($work . '/scan.mjs', $harness);
            $process = proc_open(
                sprintf('cd %s && %s scan.mjs 2>&1', escapeshellarg($work), escapeshellarg($bun)),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $work
            );

            if (!is_resource($process)) {
                self::$skipReason = 'could not start the bun scanner.';

                return;
            }

            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $decoded = json_decode(trim($out), true);
            if (!is_array($decoded) || !isset($decoded['broken'])) {
                self::$skipReason = 'the scanner did not return a result document: ' . trim($out);

                return;
            }

            self::$broken = is_array($decoded['broken']) ? $decoded['broken'] : [];
        } finally {
            foreach (glob($work . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($work);
        }
    }

    public function testEveryComponentScriptParses(): void
    {
        self::scan();

        if (self::$skipReason !== null) {
            self::markTestSkipped(self::$skipReason);
        }

        $report = [];
        foreach (self::$broken as $name => $error) {
            $report[] = $name . ': ' . $error;
        }

        self::assertSame([], $report, implode("\n\n", [
            'These Phui components have a <script> block that is not valid JavaScript. The '
            . 'loader compiles the template and serves the script verbatim, so nothing else in '
            . 'the suite notices: the module builds, the classes resolve, the props are declared, '
            . 'and the component is simply dead in the browser.',
            '',
            implode("\n", $report),
        ]));
    }

    public function testTheScanActuallyCoveredTheKit(): void
    {
        self::scan();

        if (self::$skipReason !== null) {
            self::markTestSkipped(self::$skipReason);
        }

        // A green run over zero files is not a pass; guard against the scan quietly
        // collecting nothing (a wrong glob, a parser change, a moved directory).
        $scripted = 0;
        foreach (glob(self::PHUI_DIR . '/*.phue') ?: [] as $file) {
            $structure = (new PhueTemplateParser())->parseStructure($file);
            if (($structure['script'] ?? null) !== null) {
                $scripted++;
            }
        }

        self::assertGreaterThan(50, $scripted, 'the kit is expected to ship well over fifty scripted components');
    }
}