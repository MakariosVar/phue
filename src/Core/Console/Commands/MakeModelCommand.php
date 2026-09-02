<?php

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;

/**
 * CLI Command to generate a new Active Record Model class boilerplate.
 *
 * @package Phue\Core\Console\Commands
 */
class MakeModelCommand implements CommandInterface
{
    /**
     * Gets the unique command identifier name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'make:model';
    }

    /**
     * Gets a short description of the command for the CLI menu.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Creates a new Active Record Model class (e.g. ./bin/phue make:model User).';
    }

    /**
     * Executes the model generation process using the provided arguments.
     *
     * @param array<int, string> $args Command line arguments (expects model name as first index).
     * @return int Status code (0 for success, 1 for failure).
     */
    public function execute(array $args): int
    {
        if (empty($args[0])) {
            fwrite(STDERR, "\033[31mError: Model name is required. Usage: ./bin/phue make:model <Name>\033[0m\n");
            return 1;
        }

        $name = ucfirst($args[0]);
        $table = strtolower($name) . 's';

        $dir = getcwd() . '/App/Models';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = $dir . '/' . $name . '.php';

        if (file_exists($filePath)) {
            fwrite(STDERR, sprintf("\033[31mError: Model %s already exists!\033[0m\n", $name));
            return 1;
        }

        $template = <<<PHP
<?php

namespace App\Models;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

class {$name} extends BaseActiveRecord
{
    protected string \$table = '{$table}';
}
PHP;

        file_put_contents($filePath, $template);
        echo sprintf("\033[32mModel created successfully:\033[0m App/Models/%s.php\n", $name);

        return 0;
    }
}