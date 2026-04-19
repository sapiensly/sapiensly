<?php

namespace App\Enums;

enum WhatsAppContentType: string
{
    case Text = 'text';
    case Image = 'image';
    case Document = 'document';
    case Audio = 'audio';
    case Video = 'video';
    case Location = 'location';
    case Template = 'template';
    case Sticker = 'sticker';
    case Contacts = 'contacts';

    public function isMedia(): bool
    {
        return in_array($this, [
            self::Image,
            self::Document,
            self::Audio,
            self::Video,
            self::Sticker,
        ], true);
    }
}
