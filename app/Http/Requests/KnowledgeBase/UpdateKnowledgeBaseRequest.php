<?php

namespace App\Http\Requests\KnowledgeBase;

use App\Models\KnowledgeBase;
use Illuminate\Foundation\Http\FormRequest;

class UpdateKnowledgeBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $knowledgeBase = $this->route('knowledge_base');

        return $knowledgeBase instanceof KnowledgeBase && $knowledgeBase->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:50'],
            'config' => ['nullable', 'array'],
            'config.chunk_size' => ['nullable', 'integer', 'min:100', 'max:4000'],
            'config.chunk_overlap' => ['nullable', 'integer', 'min:0', 'max:500'],
        ];
    }
}
