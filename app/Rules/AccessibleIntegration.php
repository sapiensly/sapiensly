<?php

namespace App\Rules;

use App\Enums\IntegrationKind;
use App\Models\Integration;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that the given integration id refers to a Connection the user may
 * access and whose kind can back this tool: HTTP tools (rest_api / graphql)
 * borrow an `http` connection; database tools borrow a `database` one. MCP
 * connections are consumed by mcp tools, never here.
 */
class AccessibleIntegration implements ValidationRule
{
    /**
     * @param  array<int, string>  $allowedKinds
     */
    public function __construct(
        private readonly ?User $user,
        private readonly array $allowedKinds = ['http'],
    ) {}

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

        $kind = $integration->kind instanceof IntegrationKind
            ? $integration->kind->value
            : ($integration->kind ?? 'http');

        if (! in_array($kind, $this->allowedKinds, true)) {
            $fail('The selected connection cannot back this tool.');
        }
    }
}
