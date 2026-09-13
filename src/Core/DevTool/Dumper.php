<?php

declare(strict_types=1);

namespace Phue\Core\DevTool;

/**
 * Class Dumper
 *
 * Renders variables in a clean, styled HTML format.
 *
 * @package Phue\Core\DevTool
 */
class Dumper
{
    /**
     * Dump variable as formatted HTML.
     *
     * @param mixed $var Variable to dump.
     * @param bool $return Whether to return the output instead of echoing.
     * @return string
     */
    public function dump(mixed $var, bool $return = false): string
    {
        ob_start();
        echo '<pre style="background: #1e1e1e; color: #dcdcdc; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 13px; overflow: auto; z-index: 999999; position: relative;">';
        var_dump($var);
        echo '</pre>';
        $output = (string) ob_get_clean();

        if ($return) {
            return $output;
        }

        echo $output;
        return $output;
    }
}