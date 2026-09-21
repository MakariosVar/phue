<?php

declare(strict_types=1);

namespace Phue\Modules\Phii\Generator;

use Phue\Modules\Phii\Generator\FileMutation;

/**
 * Generates modular directory skeletons and configuration file mutations.
 *
 * @package Phue\Modules\Phii\Generator
 */
final class ModuleGenerator
{
    /**
     * Generate new module directory structure and configuration file mutations.
     *
     * @param string              $moduleName Module name.
     * @param string              $basePath   Project base path.
     * @param array<string, bool> $options    Module options ('auth' => bool, 'api' => bool).
     *
     * @return array<int, FileMutation> List of file mutations for the module configuration.
     */
    public function generate(
        string $moduleName,
        string $basePath = '',
        array $options = ['auth' => false, 'api' => false]
    ): array {
        $cleanBasePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $moduleDir = $cleanBasePath . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $moduleName;
        
        $mutations = [];

        // 1. Render module-config.php template as a FileMutation
        $templateFile = __DIR__ . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'module-config.php';
        $template = file_get_contents($templateFile);
        if ($template !== false) {
            $configContent = str_replace('{{ MODULE_NAME }}', $moduleName, $template);
            $configFile = $moduleDir . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'module.php';
            
            $mutations[] = new FileMutation($configFile, $configContent);
        }

        // 2. Add .gitkeep mutations for core subdirectories to ensure full skeleton generation
        $subDirs = ['Config', 'Controller', 'Views', 'Model'];
        foreach ($subDirs as $subDir) {
            $gitkeepPath = $moduleDir . DIRECTORY_SEPARATOR . $subDir . DIRECTORY_SEPARATOR . '.gitkeep';
            $mutations[] = new FileMutation($gitkeepPath, "");
        }

        return $mutations;
    }
}