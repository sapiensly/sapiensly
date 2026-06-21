<?php

namespace App\Http\Requests\Chatbot;

use App\Support\Chatbots\ChatbotConfigRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreChatbotRequest extends FormRequest
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
            ...ChatbotConfigRules::widgetConfig(),
        ];
    }

    public function messages(): array
    {
        return [
            'config.appearance.primary_color.regex' => __('Color must be a valid hex code (e.g., #3B82F6).'),
        ];
    }
}
