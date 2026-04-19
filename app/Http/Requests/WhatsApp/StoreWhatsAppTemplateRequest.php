<?php

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

class StoreWhatsAppTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:10'],
            'category' => ['required', 'in:marketing,utility,authentication'],
            'components' => ['required', 'array'],
            'status' => ['nullable', 'in:approved,pending,rejected,paused,unknown'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => __('Template names must be lowercase, digits, and underscores only.'),
        ];
    }
}
