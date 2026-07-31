<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // isSysAdmin(), not hasRole(): the role is global while spatie's
        // check is scoped to the active organization's team.
        return $this->user()?->isSysAdmin() ?? false;
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
