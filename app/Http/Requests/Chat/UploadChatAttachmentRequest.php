<?php

namespace App\Http\Requests\Chat;

use App\Models\Chat;
use Illuminate\Foundation\Http\FormRequest;

class UploadChatAttachmentRequest extends FormRequest
{
    /** Hard server ceiling per upload. */
    public const MAX_BYTES = 30 * 1024 * 1024; // 30 MB

    public function authorize(): bool
    {
        $chat = $this->route('chat');

        return $chat instanceof Chat && $chat->user_id === $this->user()?->id;
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
                'mimes:jpg,jpeg,png,gif,webp,pdf,txt,md,csv,json,mp3,wav,m4a,ogg,docx',
            ],
        ];
    }
}
