<?php

namespace App\Http\Requests\Chat;

use App\Models\Chat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        $chat = $this->route('chat');

        return $chat instanceof Chat && $chat->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'chat_project_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('tenant.chat_projects', 'id')->where('user_id', $this->user()?->id),
            ],
        ];
    }
}
