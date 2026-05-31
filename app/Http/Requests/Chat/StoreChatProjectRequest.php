<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class StoreChatProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'custom_instructions' => ['nullable', 'string', 'max:20000'],
            'knowledge_base_ids' => ['nullable', 'array', 'max:20'],
            'knowledge_base_ids.*' => ['string'],
        ];
    }
}
