<?php

namespace App\Services;

use App\Models\KnowledgeBase;

class ChunkingService
{
    private int $defaultChunkSize = 1000;

    private int $defaultChunkOverlap = 200;

    /**
     * Chunk content into overlapping segments.
     *
     * @return array<array{content: string, index: int, metadata: array}>
     */
    public function chunk(string $content, int $chunkSize = 1000, int $overlap = 200): array
    {
        if (empty(trim($content))) {
            return [];
        }

        // Normalize whitespace
        $content = preg_replace('/\r\n|\r/', "\n", $content);
        $content = trim($content);

        $chunks = [];
        $position = 0;
        $index = 0;
        $contentLength = mb_strlen($content);

        while ($position < $contentLength) {
            // Extract chunk
            $chunk = mb_substr($content, $position, $chunkSize);

            // Try to find a natural break point if we're not at the end
            if ($position + $chunkSize < $contentLength) {
                $chunk = $this->findNaturalBreak($chunk);
            }

            $chunkContent = trim($chunk);

            if (! empty($chunkContent)) {
                $chunks[] = [
                    'content' => $chunkContent,
                    'index' => $index,
                    'metadata' => [
                        'start_position' => $position,
                        'end_position' => $position + mb_strlen($chunk),
                        'char_count' => mb_strlen($chunkContent),
                    ],
                ];
                $index++;
            }

            // Move position forward, accounting for overlap
            $position += mb_strlen($chunk) - $overlap;

            // Ensure we make progress even if chunk is smaller than overlap
            if ($position <= $chunks[count($chunks) - 1]['metadata']['start_position'] ?? -1) {
                $position = ($chunks[count($chunks) - 1]['metadata']['end_position'] ?? 0);
            }
        }

        return $chunks;
    }

    /**
     * Chunk content using settings from a KnowledgeBase.
     *
     * @return array<array{content: string, index: int, metadata: array}>
     */
    public function chunkForKnowledgeBase(string $content, KnowledgeBase $knowledgeBase): array
    {
        $config = $knowledgeBase->config ?? [];

        $chunkSize = $config['chunk_size'] ?? $this->defaultChunkSize;
        $overlap = $config['chunk_overlap'] ?? $this->defaultChunkOverlap;

        return $this->chunk($content, $chunkSize, $overlap);
    }

    /**
     * Find a natural break point (paragraph or sentence boundary).
     */
    private function findNaturalBreak(string $chunk): string
    {
        $originalLength = mb_strlen($chunk);

        // Try to find a paragraph break (double newline)
        $lastParagraph = mb_strrpos($chunk, "\n\n");
        if ($lastParagraph !== false && $lastParagraph > $originalLength * 0.5) {
            return mb_substr($chunk, 0, $lastParagraph + 2);
        }

        // Try to find a sentence break (. or ! or ? followed by space or newline)
        $sentencePattern = '/[.!?][\s\n]/';
        if (preg_match_all($sentencePattern, $chunk, $matches, PREG_OFFSET_CAPTURE)) {
            $lastMatch = end($matches[0]);
            $lastSentenceEnd = $lastMatch[1] + 1;

            // Only use if it's in the second half of the chunk
            if ($lastSentenceEnd > $originalLength * 0.5) {
                return mb_substr($chunk, 0, $lastSentenceEnd + 1);
            }
        }

        // Try to find a newline
        $lastNewline = mb_strrpos($chunk, "\n");
        if ($lastNewline !== false && $lastNewline > $originalLength * 0.5) {
            return mb_substr($chunk, 0, $lastNewline + 1);
        }

        // Try to find a space
        $lastSpace = mb_strrpos($chunk, ' ');
        if ($lastSpace !== false && $lastSpace > $originalLength * 0.7) {
            return mb_substr($chunk, 0, $lastSpace + 1);
        }

        // Return as-is if no good break point found
        return $chunk;
    }

    /**
     * Get the default chunk size.
     */
    public function getDefaultChunkSize(): int
    {
        return $this->defaultChunkSize;
    }

    /**
     * Get the default chunk overlap.
     */
    public function getDefaultChunkOverlap(): int
    {
        return $this->defaultChunkOverlap;
    }
}
