<?php

namespace App\Rules;

use App\Models\Integration;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that the given integration id refers to a Connection the user may
 * access and that can back an HTTP-shaped tool (rest_api / graphql). MCP
 * connections are rejected here — those are consumed by mcp tools, not HTTP
 * ones — so a connected action can only borrow a real HTTP connection from the
 * user's own account context.
 */
class AccessibleIntegration implements ValidationRule
{
    public function __construct(private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->user === null) {
            $fail('You must be signed in to link a connection.');

            return;
        }

        $integration = Integration::query()
            ->where('id', $value)
            ->visibleTo($this->user)
            ->first();

        if (! $integration instanceof Integration) {
            $fail('The selected connection is not available.');

            return;
        }

        if ($integration->is_mcp) {
            $fail('MCP connections cannot back an HTTP tool.');
        }
    }
}
