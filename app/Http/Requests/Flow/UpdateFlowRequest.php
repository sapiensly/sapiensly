<?php

namespace App\Http\Requests\Flow;

use App\Rules\ValidFlowDefinition;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'definition' => ['sometimes', 'required', 'array', new ValidFlowDefinition],
        ];
    }
}
