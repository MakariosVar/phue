<?php

declare(strict_types=1);

namespace Phue\Generator;

/**
 * Generates modular directory skeletons and configuration files.
 *
 * @package Phue\Generator
 */
final class ModuleGenerator
{
    /**
     * Generate a new module structure.
     *
     * @param string                $moduleName Module name.
     * @param string                $basePath   Project base path.
     * @param array<string, bool>   $options    Module options ('auth' => bool, 'api' => bool).
     *
     * @return array<string, string> Created directories and configuration path.
     */
    public function generate(
        string $moduleName,
        string $basePath = '',
        array $options = ['auth' => false, 'api' => false]
    ): array {
        $moduleDir = rtrim($basePath, '/') . "/App/Modules/{$moduleName}";
        $subDirs = [
            "{$moduleDir}/Config",
            "{$moduleDir}/Controller",
            "{$moduleDir}/Views",
            "{$moduleDir}/Model",
        ];

        $created = [];
        foreach ($subDirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                $created[] = $dir;
            }
        }

        // Render module-config.php template
        $templateFile = __DIR__ . '/templates/module-config.php';
        $template = file_get_contents($templateFile);
        if ($template !== false) {
            $configContent = str_replace('{{ MODULE_NAME }}', $moduleName, $template);
            $configFile = "{$moduleDir}/Config/module.php";
            file_put_contents($configFile, $configContent);
            $created[] = $configFile;
        }

        return $created;
    }
}