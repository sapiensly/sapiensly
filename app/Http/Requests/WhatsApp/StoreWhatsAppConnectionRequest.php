<?php

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

class StoreWhatsAppConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'display_phone_number' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'phone_number_id' => ['required', 'string', 'max:40', 'unique:whatsapp_connections,phone_number_id'],
            'business_account_id' => ['required', 'string', 'max:40'],
            'messaging_tier' => ['nullable', 'in:unverified,1k,10k,100k,unlimited'],

            // Target
            'agent_id' => ['nullable', 'string', 'exists:agents,id', 'required_without:agent_team_id'],
            'agent_team_id' => ['nullable', 'string', 'exists:agent_teams,id', 'required_without:agent_id'],

            // Credentials (auth_config)
            'auth.access_token' => ['required', 'string', 'max:500'],
            'auth.app_id' => ['required', 'string', 'max:40'],
            'auth.app_secret' => ['required', 'string', 'max:200'],
            'auth.graph_api_version' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'display_phone_number.regex' => __('Use E.164 format, e.g. +15551234567.'),
            'agent_id.required_without' => __('Select an agent or agent team.'),
            'agent_team_id.required_without' => __('Select an agent or agent team.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->agent_id && $this->agent_team_id) {
            $this->merge(['agent_team_id' => null]);
        }
    }
}
