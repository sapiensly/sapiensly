<?php

namespace App\Http\Requests\Chatbot;

use Illuminate\Foundation\Http\FormRequest;

class StoreChatbotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],

            // Target: either agent_id OR agent_team_id (not both)
            'agent_id' => ['nullable', 'string', 'exists:agents,id', 'required_without:agent_team_id'],
            'agent_team_id' => ['nullable', 'string', 'exists:agent_teams,id', 'required_without:agent_id'],

            // Config
            'config' => ['nullable', 'array'],
            'config.appearance' => ['nullable', 'array'],
            'config.appearance.primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'config.appearance.background_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'config.appearance.text_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'config.appearance.logo_url' => ['nullable', 'url', 'max:2048'],
            'config.appearance.position' => ['nullable', 'string', 'in:bottom-right,bottom-left'],
            'config.appearance.welcome_message' => ['nullable', 'string', 'max:500'],
            'config.appearance.placeholder_text' => ['nullable', 'string', 'max:100'],
            'config.appearance.widget_title' => ['nullable', 'string', 'max:50'],

            'config.behavior' => ['nullable', 'array'],
            'config.behavior.auto_open_delay' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'config.behavior.require_visitor_info' => ['nullable', 'boolean'],
            'config.behavior.collect_email' => ['nullable', 'boolean'],
            'config.behavior.collect_name' => ['nullable', 'boolean'],
            'config.behavior.show_powered_by' => ['nullable', 'boolean'],

            'config.advanced' => ['nullable', 'array'],
            'config.advanced.custom_css' => ['nullable', 'string', 'max:10000'],
            'config.advanced.custom_font_family' => ['nullable', 'string', 'max:100'],

            // Allowed origins
            'allowed_origins' => ['nullable', 'array', 'max:20'],
            'allowed_origins.*' => ['string', 'url', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'agent_id.required_without' => 'Please select an agent or agent team.',
            'agent_team_id.required_without' => 'Please select an agent or agent team.',
            'config.appearance.primary_color.regex' => 'Color must be a valid hex code (e.g., #3B82F6).',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Ensure only one of agent_id or agent_team_id is set
        if ($this->agent_id && $this->agent_team_id) {
            $this->merge(['agent_team_id' => null]);
        }
    }
}
