<?php

namespace App\Rules;

use App\Models\Integration;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that the given integration id refers to an OAuth 2.0 integration
 * the user may access, so an MCP tool can only borrow tokens from an
 * integration that actually belongs to the user's account context.
 */
class AccessibleOAuth2Integration implements ValidationRule
{
    public function __construct(private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->user === null) {
            $fail('You must be signed in to link an integration.');

            return;
        }

        $integration = Integration::query()
            ->where('id', $value)
            ->visibleTo($this->user)
            ->first();

        if (! $integration instanceof Integration) {
            $fail('The selected integration is not available.');

            return;
        }

        if (! $integration->auth_type->isOAuth2()) {
            $fail('The selected integration does not use OAuth 2.0.');
        }
    }
}
