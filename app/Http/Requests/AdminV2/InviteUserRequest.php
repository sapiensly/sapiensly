<?php

namespace App\Http\Requests\AdminV2;

use Illuminate\Foundation\Http\FormRequest;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('sysadmin') ?? false;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['nullable', 'string', 'max:255'],
            // The admin-v2 screen shows four roles — sysadmin is intentionally
            // excluded from invite (must be granted explicitly post-creation).
            'role' => ['required', 'in:owner,admin,member'],
        ];
    }
}
