<?php

declare(strict_types=1);

namespace Phue\Modules\Events;

use Phue\Core\Module;
use Phue\Core\Console\Application;

/**
 * Class EventModule
 *
 * @package Phue\Modules\Events
 */
class EventModule extends Module
{
    /**
     * @var string Module ID
     */
    public string $id = 'events';

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