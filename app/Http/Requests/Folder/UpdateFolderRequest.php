<?php

namespace App\Http\Requests\Folder;

use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateFolderRequest extends FormRequest
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
            'parent_id' => ['nullable', 'string', 'exists:folders,id'],
        ];
    }
}
