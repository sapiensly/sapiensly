<?php

namespace App\Http\Requests\Document;

use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'visibility' => ['sometimes', new Enum(Visibility::class)],
            'folder_id' => ['nullable', 'string', 'exists:folders,id'],
            // Body updates are only accepted for inline documents; the controller
            // enforces that rule against the bound model.
            'body' => ['sometimes', 'string', 'max:10485760'],
        ];
    }
}
