<?php

declare(strict_types=1);

namespace Phue\Core\UI\Exceptions;

use RuntimeException;

/**
 * Class CircularComponentReferenceException
 *
 * Thrown when a component circular reference loop is detected in the dependency graph.
 *
 * @package Phue\Core\UI\Exceptions
 */
class CircularComponentReferenceException extends RuntimeException
{
}