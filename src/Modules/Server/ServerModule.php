<?php

declare(strict_types=1);

namespace Phue\Modules\Server;

use Phue\Core\Module;
use Phue\Core\Console\Application;
use Phue\Modules\Server\Commands\SwooleServeCommand;

/**
 * Class ServerModule
 *
 * Server Module that add swoole server commands to the application
 *
 * @package Phue\Modules\Server
 */
class ServerModule extends Module
{
    /**
     * @var string Module ID
     */
    public string $id = 'server';

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
        $console->add(new SwooleServeCommand());
    }
}