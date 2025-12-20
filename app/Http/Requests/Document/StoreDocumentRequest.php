<?php

namespace App\Http\Requests\Document;

use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,txt,docx,doc,md,csv,json'],
            'name' => ['nullable', 'string', 'max:255'],
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
            'file.max' => 'The document must not be larger than 10MB.',
            'file.mimes' => 'The document must be a PDF, TXT, DOCX, DOC, MD, CSV, or JSON file.',
        ];
    }
}
