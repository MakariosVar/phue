<?php

declare(strict_types=1);

namespace Phue\Core\UI;

use RuntimeException;

/**
 * Value object representing a parsed .phue Single File Component.
 *
 * @package Phue\Core\UI
 */
readonly class PhueComponent
{
    public function __construct(
        public string $title,
        public View $view
    ) {}
}