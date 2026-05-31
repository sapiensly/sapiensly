<?php

namespace App\Http\Requests\Chat;

use App\Models\Chat;
use Illuminate\Foundation\Http\FormRequest;

class StoreChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $chat = $this->route('chat');

        return $chat instanceof Chat && $chat->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'content' => ['nullable', 'required_without:attachment_ids', 'string', 'max:50000'],
            'model' => ['nullable', 'string', 'max:100'],
            'web_search' => ['nullable', 'boolean'],
            'tool_ids' => ['nullable', 'array', 'max:50'],
            'tool_ids.*' => ['string'],
            'attachment_ids' => ['nullable', 'array', 'max:20'],
            'attachment_ids.*' => ['string'],
        ];
    }
}
