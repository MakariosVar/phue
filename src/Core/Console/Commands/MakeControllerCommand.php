<?php

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;

/**
 * CLI Command to generate a new Controller class boilerplate.
 *
 * @package Phue\Core\Console\Commands
 */
class MakeControllerCommand implements CommandInterface
{
    /**
     * Gets the unique command identifier name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'make:controller';
    }

    /**
     * Gets a short description of the command for the CLI menu.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Creates a new Controller class (e.g. ./bin/phue make:controller UserController).';
    }

    /**
     * Executes the controller generation process using the provided arguments.
     *
     * @param array<int, string> $args Command line arguments (expects controller name as first index).
     * @return int Status code (0 for success, 1 for failure).
     */
    public function execute(array $args): int
    {
        if (empty($args[0])) {
            fwrite(STDERR, "\033[31mError: Controller name is required. Usage: ./bin/phue make:controller <Name>\033[0m\n");
            return 1;
        }

        $name = ucfirst($args[0]);
        if (!str_ends_with($name, 'Controller')) {
            $name .= 'Controller';
        }

        $dir = getcwd() . '/App/Controllers';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = $dir . '/' . $name . '.php';

        if (file_exists($filePath)) {
            fwrite(STDERR, sprintf("\033[31mError: Controller %s already exists!\033[0m\n", $name));
            return 1;
        }

        $template = <<<PHP
<?php

namespace App\Controllers;

use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Routing\Attribute\Route;

class {$name}
{
    #[Route('GET', '/')]
    public function index(Request \$request): Response
    {
        return Response::json(['message' => 'Hello from {$name}!']);
    }
}
PHP;

        file_put_contents($filePath, $template);
        echo sprintf("\033[32mController created successfully:\033[0m App/Controllers/%s.php\n", $name);

        return 0;
    }
}