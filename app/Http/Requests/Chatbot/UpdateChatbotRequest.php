<?php

namespace App\Http\Requests\Chatbot;

use App\Enums\ChatbotStatus;
use App\Enums\Visibility;
use App\Support\Chatbots\ChatbotConfigRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChatbotRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(ChatbotStatus::class)],
            'visibility' => ['nullable', Rule::enum(Visibility::class)],
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
