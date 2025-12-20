<?php

namespace App\Contracts;

use App\DTOs\ToolExecutionResult;
use App\Models\Tool;

interface ToolExecutor
{
    /**
     * Execute the tool with the given parameters.
     *
     * @param  array<string, mixed>  $parameters  The parameters to pass to the tool
     * @param  array<string, mixed>  $config  The decrypted tool configuration
     */
    public function execute(Tool $tool, array $parameters, array $config): ToolExecutionResult;

    /**
     * Validate that the tool can be executed with the given parameters.
     *
     * @return array<string, string> Validation errors (empty if valid)
     */
    public function validate(Tool $tool, array $parameters, array $config): array;
}
