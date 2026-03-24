<?php

namespace App\Http\Requests\AgentTeam;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgentTeamRequest extends FormRequest
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
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:50'],

            // Agent selection - expects agent IDs for each type
            'agent_ids' => ['required', 'array'],
            'agent_ids.triage' => ['required', 'string', 'exists:agents,id'],
            'agent_ids.knowledge' => ['required', 'string', 'exists:agents,id'],
            'agent_ids.action' => ['required', 'string', 'exists:agents,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'agent_ids.triage.required' => __('Please select a Triage agent.'),
            'agent_ids.knowledge.required' => __('Please select a Knowledge agent.'),
            'agent_ids.action.required' => __('Please select an Action agent.'),
        ];
    }
}
