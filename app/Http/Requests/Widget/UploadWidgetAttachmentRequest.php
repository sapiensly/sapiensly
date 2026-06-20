<?php

namespace App\Http\Requests\Widget;

use App\Http\Requests\Chat\UploadChatAttachmentRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a file a visitor uploads to a widget conversation. Authorization is
 * handled upstream by the widget middleware (token + origin) and the
 * controller's chatbot/conversation ownership check; mirrors
 * {@see UploadChatAttachmentRequest}.
 */
class UploadWidgetAttachmentRequest extends FormRequest
{
    /** Hard server ceiling per upload. */
    public const MAX_BYTES = 30 * 1024 * 1024; // 30 MB

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.(int) (self::MAX_BYTES / 1024),
                'mimes:jpg,jpeg,png,gif,webp,pdf,txt,md,csv,json,docx',
            ],
        ];
    }
}
