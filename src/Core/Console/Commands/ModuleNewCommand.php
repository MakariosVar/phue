<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;
use Phue\Modules\Phii\Generator\FileMutation;

/**
 * CLI Command to scaffold a new framework module adhering to the module contract.
 *
 * @package Phue\Core\Console\Commands
 */
final class ModuleNewCommand implements CommandInterface
{
    /**
     * Retrieves command identifier name.
     *
     * @return string Command name.
     */
    public function getName(): string
    {
        return 'module:new';
    }

    /**
     * Retrieves command description.
     *
     * @return string Command description.
     */
    public function getDescription(): string
    {
        return 'Scaffold a new framework module under src/Modules/ following the module contract.';
    }

    /**
     * Executes the module scaffolding command.
     *
     * @param array<int, string> $args Command positional arguments and options.
     * @param mixed $outputStream Output stream resource.
     * @param mixed $errorStream Error stream resource.
     * @return int Exit code (0 for success, 1 for failure).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        // 1. Parse arguments and flags
        $moduleName = null;
        $type = 'layer';
        $requires = [];
        $outputPath = null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--type=')) {
                $type = trim(substr($arg, 7));
            } elseif (str_starts_with($arg, '--requires=')) {
                $reqsRaw = trim(substr($arg, 11));
                if ($reqsRaw !== '') {
                    $requires = array_map('trim', explode(',', $reqsRaw));
                }
            } elseif (str_starts_with($arg, '--output=')) {
                $outputPath = rtrim(substr($arg, 9), '/');
            } else {
                $moduleName = $moduleName ?? $arg;
            }
        }

        $moduleName = trim($moduleName ?? '');

        if (!$moduleName) {
            fwrite($errorStream, "Error: Missing module name argument. Usage: bin/phue module:new <Name> [--type=layer|kit] [--requires=a,b] [--output=<path>]" . PHP_EOL);
            return 1;
        }

        // 2. Validate name: ctype_alpha + UpperFirst check (e.g. Queue)
        if (!ctype_alpha($moduleName) || $moduleName !== ucfirst($moduleName)) {
            fwrite($errorStream, "Error: Module name '{$moduleName}' must be alphabetic and in PascalCase (e.g., 'Queue')." . PHP_EOL);
            return 1;
        }

        // 3. Determine module ID (lcfirst, e.g. 'queue')
        $moduleId = lcfirst($moduleName);
        $basePath = $outputPath ?? PathResolver::resolveBasePath();

        // Prepare stub replacements
        $requiresExport = '[' . implode(', ', array_map(fn($r) => "'" . $r . "'", $requires)) . ']';

        $moduleCode = <<<PHP
<?php

declare(strict_types=1);

namespace Phue\Modules\\$moduleName;

use Phue\Core\Module;
use Phue\Core\Console\Application;

/**
 * Class {$moduleName}Module
 *
 * Scaffolded module following the Module contract.
 *
 * @package Phue\\Modules\\{$moduleName}
 */
class {$moduleName}Module extends Module
{
    public string \$id = '{$moduleId}';
    public string \$type = '{$type}';
    public array \$requires = {$requiresExport};

    /**
     * Initialize the module.
     *
     * Calls config(), bindings(), routes() in order.
     *
     * @return void
     */
    public function init(): void
    {
        \$this->config();
        \$this->bindings();
        \$this->routes();
    }

    /**
     * Merge default config with config/{$moduleId}.php.
     *
     * @return void
     */
    protected function config(): void {}

    /**
     * Register DI bindings for this module.
     *
     * @return void
     */
    protected function bindings(): void {}

    /**
     * Register routes for this module.
     *
     * @return void
     */
    protected function routes(): void {}

    /**
     * Register console commands provided by this module.
     *
     * @param Application \$console
     * @return void
     */
    public function registerCommands(Application \$console): void {}
}
PHP;

        $configCode = <<<PHP
<?php

declare(strict_types=1);

return ['enabled' => true];
PHP;

        $testCode = <<<PHP
<?php

declare(strict_types=1);

namespace Phue\Tests\\$moduleName;

use PHPUnit\Framework\TestCase;
use Phue\Modules\\$moduleName\\{$moduleName}Module;

/**
 * Tests for {$moduleName}Module
 *
 * Verifies Module contract compliance.
 * Tier-1 only: uses Phue\Tests\Support + own logic.
 *
 * @package Phue\\Tests\\{$moduleName}
 */
class {$moduleName}ModuleTest extends TestCase
{
    /**
     * Verify module ID and type match scaffold input.
     *
     * @return void
     */
    public function testModuleClassExists(): void
    {
        \$m = new {$moduleName}Module();
        \$this->assertSame('{$moduleId}', \$m->id);
        \$this->assertSame('{$type}', \$m->type);
    }

    /**
     * Verify the scaffolded class implements the Module contract.
     *
     * @return void
     */
    public function testModuleImplementsContract(): void
    {
        \$this->assertInstanceOf(\Phue\Core\Module::class, new {$moduleName}Module());
    }
}
PHP;

        // Relative path paths for FileMutations
        $moduleFileRel = "src/Modules/{$moduleName}/{$moduleName}Module.php";
        $configFileRel = "config/{$moduleId}.php";
        $testFileRel = "tests/{$moduleName}/{$moduleName}ModuleTest.php";
        $gitkeepFileRel = "tests/{$moduleName}/.gitkeep";

        $gitkeepContent = "";

        // 4. Generate files using FileMutation pattern (Zero I/O until write())
        $mutations = [
            new FileMutation($basePath . '/' . $moduleFileRel, $moduleCode),
            new FileMutation($basePath . '/' . $configFileRel, $configCode),
            new FileMutation($basePath . '/' . $testFileRel, $testCode),
            new FileMutation($basePath . '/' . $gitkeepFileRel, $gitkeepContent),
        ];

        fwrite($outputStream, "Scaffolding framework module [{$moduleName}]..." . PHP_EOL);

        foreach ($mutations as $mutation) {
            $mutation->write();
            // Display created file relative paths
            $relativePath = str_replace($basePath . '/', '', $mutation->path);
            fwrite($outputStream, "  [created] {$relativePath}" . PHP_EOL);
        }

        // 5. Output next steps guidance
        fwrite($outputStream, PHP_EOL . "Successfully created module '{$moduleName}'!" . PHP_EOL);
        fwrite($outputStream, "Next steps:" . PHP_EOL);
        fwrite($outputStream, "  1. Register 'Phue\\Modules\\{$moduleName}\\{$moduleName}Module' inside config/app.php under 'app.modules'." . PHP_EOL);
        fwrite($outputStream, "  2. Run 'bin/phue module:list' to verify your new module configuration graph." . PHP_EOL);

        return 0;
    }
}