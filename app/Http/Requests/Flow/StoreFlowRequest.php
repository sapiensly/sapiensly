<?php

namespace App\Http\Requests\Flow;

use App\Rules\ValidFlowDefinition;
use Illuminate\Foundation\Http\FormRequest;

class StoreFlowRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'definition' => ['required', 'array', new ValidFlowDefinition],
        ];
    }
}
