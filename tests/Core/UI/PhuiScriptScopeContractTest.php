<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\TestCase;

/**
 * Static contract tests for the <script> blocks of every built-in Phui
 * component.
 *
 * The <script> block of a .phue file is served VERBATIM as an ESM module
 * (buildModule concatenates template + script). It is NOT macro-compiled.
 * Therefore Options-API code inside it must reference the instance through
 * `this` — bare `$props`/`$emit`/... are only available in compiled template
 * render functions, not in plain module scope.
 *
 * Locks:
 *  - no bare `$props` (or other $instance helpers) in any component script,
 *  - every HTML-first component with no <script> is skipped (scriptless).
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhuiScriptScopeContractTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    private function componentScripts(): array
    {
        $scripts = [];

        foreach (glob(self::PHUI_DIR . '/*.phue') ?: [] as $file) {
            $parser = new PhueTemplateParser();
            $structure = $parser->parseStructure($file);

            if (($structure['script'] ?? null) === null) {
                continue; // HTML-first partial (e.g. PhNavigation): scriptless by design
            }

            $scripts[basename($file, '.phue')] = $structure['script'];
        }

        return $scripts;
    }

    public function testEveryComponentScriptHasAtLeastOneComponentOption(): void
    {
        $scripts = $this->componentScripts();

        $this->assertNotEmpty($scripts, 'the Phui library must expose at least one scripted component');

        foreach ($scripts as $name => $script) {
            $this->assertMatchesRegularExpression(
                '/export default/',
                $script,
                "{$name}: script must export a component options object",
            );
        }
    }

    /**
     * Regression lock for the $props ReferenceError (PhInput/PhTextarea/
     * PhSelect/PhRadio) that broke every client-side render at runtime.
     */
    public function testScriptsNeverUseBareDollarProps(): void
    {
        foreach ($this->componentScripts() as $name => $script) {
            $this->assertDoesNotMatchRegularExpression(
                '/(^|[^-.\w])\$props\b/',
                $script,
                "{$name}: Options-API <script> modules cannot read bare \$props; use \$this->props "
                . '(bare $props only exists in compiled template scope)',
            );
        }
    }

    public function testScriptsNeverUseOtherBareInstanceHelpers(): void
    {
        foreach ($this->componentScripts() as $name => $script) {
            $this->assertDoesNotMatchRegularExpression(
                '/(^|[^-.\w])\$(emit|refs|el|nextTick|attrs|slots|watch|forceUpdate)\b/',
                $script,
                "{$name}: instance \$ helpers (emit/refs/el/...) must be reached through \$this in Options-API scripts",
            );
        }
    }
}