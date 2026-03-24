<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\KnowledgeBaseDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentParserService
{
    /**
     * The disk to use for document storage.
     */
    protected string $disk = 'documents';

    /**
     * Parse a KnowledgeBaseDocument and extract its text content.
     */
    public function parse(KnowledgeBaseDocument $document): string
    {
        // If content is already stored, return it
        if ($document->content) {
            return $document->content;
        }

        $type = $document->type;

        // For URL type, fetch and parse
        if ($type === DocumentType::Url) {
            return $this->parseUrl($document->source);
        }

        // For file-based documents, read from storage
        if (! $document->file_path) {
            throw new \RuntimeException('Document has no file path');
        }

        // Get file content from S3 and save to temp file for parsing
        $tempFile = $this->downloadToTemp($document->file_path, $type);

        try {
            return $this->parseFile($tempFile, $type);
        } finally {
            // Clean up temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Download file from storage to a temporary local file.
     */
    protected function downloadToTemp(string $filePath, DocumentType $type): string
    {
        $storage = Storage::disk($this->disk);

        if (! $storage->exists($filePath)) {
            throw new \RuntimeException("File not found in storage: {$filePath}");
        }

        // Create temp file with proper extension for parsers that need it
        $extension = $type->extension();
        $tempFile = tempnam(sys_get_temp_dir(), 'doc_').'.'.$extension;

        // Download content to temp file
        $content = $storage->get($filePath);
        file_put_contents($tempFile, $content);

        return $tempFile;
    }

    /**
     * Parse a file and extract its text content.
     */
    public function parseFile(string $filePath, DocumentType $type): string
    {
        return match ($type) {
            DocumentType::Pdf => $this->parsePdf($filePath),
            DocumentType::Docx => $this->parseDocx($filePath),
            DocumentType::Txt, DocumentType::Md => $this->parseText($filePath),
            DocumentType::Csv => $this->parseCsv($filePath),
            DocumentType::Json => $this->parseJson($filePath),
            DocumentType::Url => throw new \InvalidArgumentException('Use parseUrl() for URL documents'),
        };
    }

    /**
     * Parse a PDF file.
     */
    private function parsePdf(string $filePath): string
    {
        $parser = new PdfParser;
        $pdf = $parser->parseFile($filePath);

        return $pdf->getText();
    }

    /**
     * Parse a DOCX file.
     */
    private function parseDocx(string $filePath): string
    {
        $phpWord = IOFactory::load($filePath, 'Word2007');

        $text = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->extractTextFromElement($element);
            }
        }

        return trim($text);
    }

    /**
     * Extract text from a PHPWord element.
     */
    private function extractTextFromElement($element): string
    {
        $text = '';

        if (method_exists($element, 'getText')) {
            $elementText = $element->getText();
            if (is_string($elementText)) {
                $text .= $elementText;
            } elseif (is_object($elementText) && method_exists($elementText, 'getText')) {
                $text .= $elementText->getText();
            }
        }

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $childElement) {
                $text .= $this->extractTextFromElement($childElement);
            }
        }

        // Add newline after paragraphs
        if ($element instanceof TextRun ||
            $element instanceof Text) {
            // Don't add extra newlines for inline elements
        } else {
            $text .= "\n";
        }

        return $text;
    }

    /**
     * Parse a plain text or markdown file.
     */
    private function parseText(string $filePath): string
    {
        return file_get_contents($filePath);
    }

    /**
     * Parse a CSV file.
     */
    private function parseCsv(string $filePath): string
    {
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \RuntimeException("Could not open CSV file: {$filePath}");
        }

        $lines = [];
        $headers = fgetcsv($handle);

        if ($headers) {
            $lines[] = implode(' | ', $headers);
            $lines[] = str_repeat('-', 50);

            while (($row = fgetcsv($handle)) !== false) {
                $rowText = [];
                foreach ($row as $index => $value) {
                    $header = $headers[$index] ?? "Column {$index}";
                    $rowText[] = "{$header}: {$value}";
                }
                $lines[] = implode(', ', $rowText);
            }
        }

        fclose($handle);

        return implode("\n", $lines);
    }

    /**
     * Parse a JSON file.
     */
    private function parseJson(string $filePath): string
    {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: '.json_last_error_msg());
        }

        return $this->jsonToText($data);
    }

    /**
     * Convert JSON data to readable text.
     */
    private function jsonToText(array $data, int $depth = 0): string
    {
        $text = '';
        $indent = str_repeat('  ', $depth);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $text .= "{$indent}{$key}:\n";
                $text .= $this->jsonToText($value, $depth + 1);
            } else {
                $text .= "{$indent}{$key}: {$value}\n";
            }
        }

        return $text;
    }

    /**
     * Fetch and parse a URL.
     */
    public function parseUrl(string $url): string
    {
        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("Failed to fetch URL: {$url}");
        }

        $html = $response->body();

        // Strip HTML tags but preserve structure
        $text = $this->htmlToText($html);

        return trim($text);
    }

    /**
     * Convert HTML to plain text while preserving structure.
     */
    private function htmlToText(string $html): string
    {
        // Remove script and style elements
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

        // Add newlines for block elements
        $html = preg_replace('/<\/(p|div|h[1-6]|li|tr|br)[^>]*>/i', "\n", $html);
        $html = preg_replace('/<(br)[^>]*>/i', "\n", $html);

        // Strip remaining HTML tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n/', "\n\n", $text);

        return trim($text);
    }
}
