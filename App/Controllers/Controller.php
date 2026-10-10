<?php

declare(strict_types=1);

namespace App\Controllers;

use Phue\Core\UI\ComponentRenderer;
use Phue\Core\Container;

/**
 * Base controller providing shared ComponentRenderer and Container dependencies.
 *
 * All application controllers extend this class to avoid repeating
 * the constructor injection of ComponentRenderer and Container.
 *
 * @package App\Controllers
 */
abstract class Controller
{
    public function __construct(
        protected ComponentRenderer $ui,
        protected Container $container
    ) {}
}