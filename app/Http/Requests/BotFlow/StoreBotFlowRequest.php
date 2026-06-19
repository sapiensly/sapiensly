<?php

namespace App\Http\Requests\BotFlow;

use App\Rules\ValidBotFlowDefinition;
use Illuminate\Foundation\Http\FormRequest;

class StoreBotFlowRequest extends FormRequest
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
            'definition' => ['required', 'array', new ValidBotFlowDefinition],
        ];
    }
}
