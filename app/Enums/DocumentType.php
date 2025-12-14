<?php

namespace App\Enums;

enum DocumentType: string
{
    case Pdf = 'pdf';
    case Txt = 'txt';
    case Docx = 'docx';
    case Md = 'md';
    case Url = 'url';
    case Csv = 'csv';
    case Json = 'json';

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Txt => 'Text',
            self::Docx => 'Word Document',
            self::Md => 'Markdown',
            self::Url => 'URL',
            self::Csv => 'CSV',
            self::Json => 'JSON',
        };
    }

    public function mimeTypes(): array
    {
        return match ($this) {
            self::Pdf => ['application/pdf'],
            self::Txt => ['text/plain'],
            self::Docx => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            self::Md => ['text/markdown', 'text/plain'],
            self::Url => [],
            self::Csv => ['text/csv', 'application/csv'],
            self::Json => ['application/json'],
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Pdf => 'pdf',
            self::Txt => 'txt',
            self::Docx => 'docx',
            self::Md => 'md',
            self::Url => '',
            self::Csv => 'csv',
            self::Json => 'json',
        };
    }
}
