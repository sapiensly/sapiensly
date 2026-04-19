<?php

namespace App\Http\Requests\WhatsApp;

use App\Enums\ChannelStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWhatsAppConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $connection = $this->route('whatsapp_connection');
        $connectionId = is_object($connection) ? $connection->getKey() : $connection;

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::enum(ChannelStatus::class)],
            'display_phone_number' => ['sometimes', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'messaging_tier' => ['sometimes', 'in:unverified,1k,10k,100k,unlimited'],

            'agent_id' => ['sometimes', 'nullable', 'string', 'exists:agents,id'],
            'agent_team_id' => ['sometimes', 'nullable', 'string', 'exists:agent_teams,id'],

            // Credentials: each field is optional on update (blank = keep existing).
            'auth.access_token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'auth.app_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'auth.app_secret' => ['sometimes', 'nullable', 'string', 'max:200'],
            'auth.graph_api_version' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }
}
