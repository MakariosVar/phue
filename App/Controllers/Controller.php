<?php

declare(strict_types=1);

namespace App\Controllers;

use Phue\Core\UI\ComponentRenderer;

/**
 * Base controller providing shared ComponentRenderer dependency.
 *
 * All application controllers extend this class to avoid repeating
 * the constructor injection of ComponentRenderer.
 *
 * @package App\Controllers
 */
abstract class Controller
{
    public function __construct(protected ComponentRenderer $ui) {}
}