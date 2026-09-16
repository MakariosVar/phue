<?php

declare(strict_types=1);

namespace Phue\Modules\Compiler;

use Phue\Core\Module;
use Phue\Core\Console\Application;

/**
 * Class CompilerModule
 *
 * Compiler Module that adds compiler commands to the application
 *
 * @package Phue\Modules\Compiler
 */
class CompilerModule extends Module
{
    /**
     * @var string Module ID
     */
    public string $id = 'compiler';

    /**
     * Initialize the module
     *
     * @return void
     */
    public function init(): void
    {
        $this->config();
        $this->bindings();
        $this->routes();
    }

    /**
     * Register commands
     *
     * @param Application $console
     * @return void
     */
    public function registerCommands(Application $console): void
    {
    }
}