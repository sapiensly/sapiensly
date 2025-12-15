<?php

namespace App\Http\Requests\Folder;

use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'visibility' => ['nullable', new Enum(Visibility::class)],
            'parent_id' => ['nullable', 'string', 'exists:folders,id'],
        ];
    }
}
