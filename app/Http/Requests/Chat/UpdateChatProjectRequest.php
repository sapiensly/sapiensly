<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatProject;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChatProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('chat_project');

        return $project instanceof ChatProject && $project->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'custom_instructions' => ['nullable', 'string', 'max:20000'],
            'knowledge_base_ids' => ['nullable', 'array', 'max:20'],
            'knowledge_base_ids.*' => ['string'],
        ];
    }
}
