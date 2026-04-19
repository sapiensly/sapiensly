<?php

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

class ReplyWhatsAppConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:16384'],
            'template_id' => ['nullable', 'string', 'exists:whatsapp_templates,id'],
            'template_params' => ['nullable', 'array'],
        ];
    }
}
