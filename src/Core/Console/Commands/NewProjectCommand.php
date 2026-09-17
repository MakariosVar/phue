<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;
use Phue\Modules\Phii\Generator\FileMutation;

/**
 * CLI Command to scaffold a minimal temporary project around specific modules (Isolation CI harness).
 *
 * @package Phue\Core\Console\Commands
 */
final class NewProjectCommand implements CommandInterface
{
    /**
     * The group this command belongs to.
     *
     * Commands in the same group are listed together in the help output.
     */
    private string $group = 'Build';
    
    /**
     * Gets the group this command belongs to.
     *
     * @return string Command group name.
     */
    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * Retrieves command identifier name.
     *
     * @return string Command name.
     */
    public function getName(): string
    {
        return 'new';
    }

    /**
     * Retrieves command description.
     *
     * @return string Command description.
     */
    public function getDescription(): string
    {
        return 'Scaffold a minimal project around a module (isolation harness) to verify subportability.';
    }

    /**
     * Executes the isolation project scaffolding command.
     *
     * @param array<int, string> $args Command arguments and options.
     * @param mixed $outputStream Output stream resource.
     * @param mixed $errorStream Error stream resource.
     * @return int Exit code (0 for success, 1 for failure).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $modulesFlag = null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--modules=')) {
                $modulesFlag = trim(substr($arg, 10));
                break;
            }
        }

        if (!$modulesFlag) {
            fwrite($errorStream, "Error: Missing required flag '--modules=<moduleName>'. Usage: bin/phue new --modules=queue" . PHP_EOL);
            return 1;
        }

        $basePath = PathResolver::resolveBasePath();
        $targetModuleNames = array_map('trim', explode(',', $modulesFlag));
        
        $resolvedModules = [];
        $transitiveClosure = [];

        // 1. Validate modules and compute transitive closure
        foreach ($targetModuleNames as $moduleName) {
            if (!ctype_alpha($moduleName) || $moduleName !== ucfirst($moduleName)) {
                fwrite($errorStream, "Error: Module name '{$moduleName}' must be in PascalCase (e.g., 'Queue')." . PHP_EOL);
                return 1;
            }

            $moduleClass = "Phue\\Modules\\{$moduleName}\\{$moduleName}Module";
            if (!class_exists($moduleClass)) {
                fwrite($errorStream, "Error: Module class '{$moduleClass}' not found under src/Modules/." . PHP_EOL);
                return 1;
            }

            $resolvedModules[$moduleName] = $moduleClass;

            // Transitive dependency calculation algorithm
            $instance = new $moduleClass();
            $moduleId = $instance->id ?? lcfirst($moduleName);
            $transitiveClosure[$moduleId] = $moduleClass;

            $pending = $instance->requires ?? [];
            while (!empty($pending)) {
                $depId = array_shift($pending);
                if (isset($transitiveClosure[$depId])) {
                    continue;
                }

                // Convert depId back to PascalCase FQCN
                $depPascal = ucfirst($depId);
                $depClass = "Phue\\Modules\\{$depPascal}\\{$depPascal}Module";

                if (class_exists($depClass)) {
                    $transitiveClosure[$depId] = $depClass;
                    $depInstance = new $depClass();
                    foreach ($depInstance->requires as $subDep) {
                        if (!isset($transitiveClosure[$subDep])) {
                            $pending[] = $subDep;
                        }
                    }
                }
            }
        }

        // 2. Create isolated temporary directory
        $primaryModuleKey = array_key_first($resolvedModules);
        $tmpDir = sys_get_temp_dir() . '/phue_iso_' . strtolower($primaryModuleKey) . '_' . uniqid();

        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            fwrite($errorStream, "Error: Failed to create temporary isolation directory at {$tmpDir}." . PHP_EOL);
            return 1;
        }

        fwrite($outputStream, "Scaffolding isolation harness for module(s) [{$modulesFlag}] at {$tmpDir}..." . PHP_EOL);

        // 3. Generate composer.json
        $composerJsonData = [
            "name" => "phue/isolation-" . strtolower($primaryModuleKey),
            "require" => [
                "phue/phue" => "dev-main"
            ],
            "repositories" => [
                [
                    "type" => "path",
                    "url" => $basePath
                ]
            ]
        ];
        $composerJsonContent = json_encode($composerJsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // 4. Generate config/app.php
        $moduleClassEntries = [];
        foreach ($transitiveClosure as $cls) {
            $moduleClassEntries[] = "    \\{$cls}::class,";
        }
        $classesString = implode("\n", $moduleClassEntries);

        $appConfigContent = <<<PHP
<?php

declare(strict_types=1);

return [
    'name' => 'Isolation: {$primaryModuleKey}',
    'env' => 'testing',
    'debug' => true,
    'trustedProxies' => [],
    'modules' => [
{$classesString}
    ],
];
PHP;

        // 5. Generate phpunit.xml
        $phpunitXmlContent = <<<XML
<?xml version="1.0"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="{$primaryModuleKey}">
            <directory suffix="Test.php">tests/{$primaryModuleKey}</directory>
        </testsuite>
    </testsuites>
    <source>
        <include><directory suffix=".php">src</directory></include>
    </source>
</phpunit>
XML;

        $mutations = [
            new FileMutation($tmpDir . '/composer.json', $composerJsonContent),
            new FileMutation($tmpDir . '/config/app.php', $appConfigContent),
            new FileMutation($tmpDir . '/phpunit.xml', $phpunitXmlContent),
        ];

        // 6. Copy necessary module files and tests recursively
        foreach ($targetModuleNames as $modName) {
            // Copy module source folder
            $sourceSrcDir = $basePath . '/src/Modules/' . $modName;
            $destSrcDir = $tmpDir . '/src/Modules/' . $modName;
            $this->recursiveCopy($sourceSrcDir, $destSrcDir);

            // Copy module configuration file if it exists
            $modId = lcfirst($modName);
            $sourceConfigFile = $basePath . '/config/' . $modId . '.php';
            if (file_exists($sourceConfigFile)) {
                $mutations[] = new FileMutation($tmpDir . '/config/' . $modId . '.php', (string) file_get_contents($sourceConfigFile));
            }

            // Copy module test directory
            $sourceTestDir = $basePath . '/tests/' . $modName;
            $destTestDir = $tmpDir . '/tests/' . $modName;
            if (is_dir($sourceTestDir)) {
                $this->recursiveCopy($sourceTestDir, $destTestDir);
            } else {
                if (!is_dir($destTestDir)) {
                    mkdir($destTestDir, 0777, true);
                }
                $mutations[] = new FileMutation($destTestDir . '/.gitkeep', '');
            }
        }

        // Copy support test fixtures (SqliteFactory, RequestFactory, etc.) if they exist
        $sourceSupportDir = $basePath . '/tests/Support';
        $destSupportDir = $tmpDir . '/tests/Support';
        if (is_dir($sourceSupportDir)) {
            $this->recursiveCopy($sourceSupportDir, $destSupportDir);
        }

        // Execute all mutations
        foreach ($mutations as $mutation) {
            $mutation->write();
        }

        // 7. Output next steps execution commands
        fwrite($outputStream, PHP_EOL . "Isolation project successfully scaffolded!" . PHP_EOL);
        fwrite($outputStream, "To execute isolation tests, run:" . PHP_EOL);
        fwrite($outputStream, "  cd {$tmpDir}" . PHP_EOL);
        fwrite($outputStream, "  composer install" . PHP_EOL);
        fwrite($outputStream, "  vendor/bin/phpunit --testsuite {$primaryModuleKey}" . PHP_EOL);

        return 0;
    }

    /**
     * Recursively copies a directory contents.
     *
     * @param string $src Source directory path.
     * @param string $dst Destination directory path.
     * @return void
     */
    private function recursiveCopy(string $src, string $dst): void
    {
        if (!is_dir($src)) {
            return;
        }

        if (!is_dir($dst)) {
            mkdir($dst, 0777, true);
        }

        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;

            if (is_dir($srcPath)) {
                $this->recursiveCopy($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }
}