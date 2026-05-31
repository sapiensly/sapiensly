<?php

namespace App\Ai\Tools;

use Laravel\Ai\Contracts\Tool as ToolContract;

/**
 * Produces a tool whose class basename equals the desired LLM-facing name.
 *
 * Needed because the AI SDK names tools after their class (class_basename),
 * so dynamic tools must each be a distinct class. We eval one tiny subclass of
 * RuntimeTool per unique name and cache it.
 */
class RuntimeToolFactory
{
    /** PHP reserved words that cannot be class names — suffixed to stay valid. */
    private const RESERVED = [
        'list', 'echo', 'print', 'class', 'function', 'return', 'for', 'foreach',
        'if', 'else', 'while', 'do', 'switch', 'case', 'new', 'array', 'string',
        'int', 'float', 'bool', 'object', 'null', 'true', 'false', 'and', 'or',
        'xor', 'use', 'namespace', 'trait', 'interface', 'enum', 'match', 'fn',
        'static', 'global', 'const', 'try', 'catch', 'throw', 'exit', 'die',
    ];

    /**
     * Wrap an inner tool under a unique class named after $desiredName.
     */
    public static function named(string $desiredName, ToolContract $inner): ToolContract
    {
        $class = self::ensureClass($desiredName);

        return new $class($inner);
    }

    /**
     * The class basename that will be used as the tool name (after sanitizing).
     */
    public static function toolName(string $desiredName): string
    {
        $base = DynamicTool::sanitizeName($desiredName);
        if ($base === '') {
            $base = 'tool';
        }
        if (in_array($base, self::RESERVED, true)) {
            $base .= '_tool';
        }

        return $base;
    }

    private static function ensureClass(string $desiredName): string
    {
        $base = self::toolName($desiredName);
        $fqcn = "App\\Ai\\Tools\\Runtime\\{$base}";

        if (! class_exists($fqcn, false)) {
            eval("namespace App\\Ai\\Tools\\Runtime; class {$base} extends \\App\\Ai\\Tools\\RuntimeTool {}");
        }

        return $fqcn;
    }
}
