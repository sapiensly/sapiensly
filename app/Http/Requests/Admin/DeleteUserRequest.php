<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class DeleteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('sysadmin') ?? false;
    }

    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            // Typing the target's email is the safety gate — the UI copy
            // makes this explicit ("type {{email}} to confirm").
            'email_confirmation' => ['required', 'string', 'in:'.$target->email],
        ];
    }

    public function messages(): array
    {
        return [
            'email_confirmation.in' => __('Email does not match — type it exactly to confirm.'),
        ];
    }
}
