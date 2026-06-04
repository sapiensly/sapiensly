<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSsoConnectionRequest extends FormRequest
{
    /**
     * Only an active owner of the user's current organization may configure SSO.
     */
    public function authorize(): bool
    {
        $organization = $this->user()?->organization;

        return $organization !== null && Gate::allows('manageSso', $organization);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['boolean'],
            'auto_provision' => ['boolean'],
            'issuer' => ['required_if:enabled,true', 'nullable', 'url', 'max:500'],
            'client_id' => ['required_if:enabled,true', 'nullable', 'string', 'max:500'],
            // Optional: a blank secret on update keeps the stored one.
            'client_secret' => ['nullable', 'string', 'max:500'],
            'allowed_domains' => ['nullable', 'array'],
            'allowed_domains.*' => ['string', 'max:255'],
        ];
    }
}
