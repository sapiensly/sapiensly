<?php

namespace App\Http\Requests\Document;

use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreInlineDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:txt,md,artifact'],
            'name' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:524288'],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:50'],
            'visibility' => ['nullable', new Enum(Visibility::class)],
            'folder_id' => ['nullable', 'string', 'exists:folders,id'],
            'knowledge_base_id' => ['nullable', 'string', 'exists:knowledge_bases,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => __('Inline documents must be Text, Markdown, or Artifact.'),
            'body.max' => __('Document body must not exceed 512 KB.'),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('visibility') === Visibility::Public->value
                && $this->input('type') !== 'artifact') {
                $validator->errors()->add(
                    'visibility',
                    __('Public visibility is only available for Artifact documents.'),
                );
            }
        });
    }
}
