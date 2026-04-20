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
    case Artifact = 'artifact';

    public function label(): string
    {
        return match ($this) {
            self::Pdf => __('PDF'),
            self::Txt => __('Text'),
            self::Docx => __('Word Document'),
            self::Md => __('Markdown'),
            self::Url => __('URL'),
            self::Csv => __('CSV'),
            self::Json => __('JSON'),
            self::Artifact => __('Artifact (HTML)'),
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
            self::Artifact => ['text/html'],
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
            self::Artifact => 'html',
        };
    }

    /**
     * True for types whose content is authored inline in the UI (stored in
     * `documents.body`) rather than uploaded as a file.
     */
    public function isInlineAuthorable(): bool
    {
        return in_array($this, [self::Txt, self::Md, self::Artifact], true);
    }

    public static function fromExtension(string $extension): self
    {
        return match (strtolower($extension)) {
            'pdf' => self::Pdf,
            'txt' => self::Txt,
            'docx', 'doc' => self::Docx,
            'md', 'markdown' => self::Md,
            'csv' => self::Csv,
            'json' => self::Json,
            'html', 'htm' => self::Artifact,
            default => throw new \InvalidArgumentException("Unsupported file extension: {$extension}"),
        };
    }
}
