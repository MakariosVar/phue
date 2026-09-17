<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Config;
use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;
use ReflectionClass;

/**
 * CLI Command to list all installed modules with their status and dependency graph.
 *
 * @package Phue\Core\Console\Commands
 */
final class ModuleListCommand implements CommandInterface
{
    /**
     * The group this command belongs to.
     *
     * Commands in the same group are listed together in the help output.
     */
    protected string $group = 'Build';
    
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
        return 'module:list';
    }

    /**
     * Retrieves command description.
     *
     * @return string Command description.
     */
    public function getDescription(): string
    {
        return 'List installed modules + dependency graph.';
    }

    /**
     * Executes the module listing command.
     *
     * @param array<int, string> $args Command arguments.
     * @param mixed $outputStream Output stream resource.
     * @param mixed $errorStream Error stream resource.
     * @return int Exit code (0 for success, 1 for failure).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        // Resolve application base path (adjust depth based on core command location)
        $basePath = dirname(__DIR__, 4);
        $configPath = $basePath . '/config';

        $config = new Config($configPath);
        $modules = (array) $config->get('app.modules', []);

        if (empty($modules)) {
            fwrite($outputStream, "No modules configured in app.modules." . PHP_EOL);
            return 0;
        }

        // Table headers
        $idHeader = 'ID';
        $typeHeader = 'Type';
        $requiresHeader = 'Requires';
        $classHeader = 'Class';

        $rows = [];
        $maxIdLen = strlen($idHeader);
        $maxTypeLen = strlen($typeHeader);
        $maxReqLen = strlen($requiresHeader);

        foreach ($modules as $class) {
            if (!class_exists($class)) {
                $rows[] = [
                    'id' => '[Missing]',
                    'type' => 'unknown',
                    'requires' => '[]',
                    'class' => $class,
                ];
                continue;
            }

            // Instantiate without calling init() to avoid side effects (routing/container bindings)
            $module = new $class();

            $id = $module->id ?? 'N/A';
            $type = $module->type ?? 'layer';
            $requiresArray = $module->requires ?? [];
            $requires = '[' . implode(', ', $requiresArray) . ']';

            $maxIdLen = max($maxIdLen, strlen($id));
            $maxTypeLen = max($maxTypeLen, strlen($type));
            $maxReqLen = max($maxReqLen, strlen($requires));

            $rows[] = [
                'id' => $id,
                'type' => $type,
                'requires' => $requires,
                'class' => $class,
            ];
        }

        // Format and print table
        $format = "%-{$maxIdLen}s   %-{$maxTypeLen}s   %-{$maxReqLen}s   %s\n";

        fwrite($outputStream, sprintf($format, $idHeader, $typeHeader, $requiresHeader, $classHeader));
        fwrite($outputStream, sprintf(
            $format,
            str_repeat('-', $maxIdLen),
            str_repeat('-', $maxTypeLen),
            str_repeat('-', $maxReqLen),
            str_repeat('-', 30)
        ) . PHP_EOL);

        foreach ($rows as $row) {
            fwrite($outputStream, sprintf(
                $format,
                $row['id'],
                $row['type'],
                $row['requires'],
                $row['class']
            ));
        }

        return 0;
    }
}