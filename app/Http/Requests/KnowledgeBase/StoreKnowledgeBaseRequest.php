<?php

namespace App\Http\Requests\KnowledgeBase;

use Illuminate\Foundation\Http\FormRequest;

class StoreKnowledgeBaseRequest extends FormRequest
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
            'config' => ['nullable', 'array'],
            'config.chunk_size' => ['nullable', 'integer', 'min:100', 'max:4000'],
            'config.chunk_overlap' => ['nullable', 'integer', 'min:0', 'max:500'],
        ];
    }
}
