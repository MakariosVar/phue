<?php

declare(strict_types=1);

namespace Phue\Modules\Compiler;

use ReflectionClass;

/**
 * Evaluates PHP classes for TypePHP AOT compilation compliance.
 *
 * @package Phue\Modules\Compiler
 */
final class AotCompatibilityChecker
{
    /**
     * Banned dynamic constructs for pure native C++ translation.
     */
    private const BANNED_PATTERNS = [
        '/\beval\s*\(/i' => 'Forbidden use of eval() detected',
        '/\bextract\s*\(/i' => 'Forbidden use of extract() detected',
        '/\bcall_user_func_array\s*\(\s*\$/i' => 'Dynamic call_user_func_array with variable string target',
        '/\$\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/' => 'Forbidden variable variables ($$var) detected',
    ];

    /**
     * Assesses a class and produces an AotCompatibilityReport.
     */
    public function checkClass(string $className): AotCompatibilityReport
    {
        $issues = [];

        if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
            return new AotCompatibilityReport($className, ["Class or type '{$className}' does not exist"]);
        }

        $reflector = new ReflectionClass($className);

        // Abstract classes or Interfaces cannot be directly instantiated natively without binding
        if ($reflector->isAbstract() || $reflector->isInterface()) {
            $issues[] = 'Type is abstract or interface (requires explicit dynamic/DI binding)';
        }

        $fileName = $reflector->getFileName();
        if ($fileName !== false && file_exists($fileName)) {
            $code = file_get_contents($fileName);
            if ($code !== false) {
                foreach (self::BANNED_PATTERNS as $pattern => $message) {
                    if (preg_match($pattern, $code)) {
                        $issues[] = $message;
                    }
                }
            }
        }

        return new AotCompatibilityReport($className, $issues);
    }
}
