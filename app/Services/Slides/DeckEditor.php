<?php

namespace App\Services\Slides;

use App\Models\Document;
use App\Models\User;

/**
 * Applies slide-level operations to a deck manifest and persists the result.
 * Single implementation shared by the MCP update_presentation tool, the Slide
 * Builder's REST endpoint and the builder AI's edit tool — one place where
 * ops semantics and whole-deck revalidation live.
 *
 * Application is atomic: any op error or validation failure returns the error
 * and leaves the input untouched.
 */
class DeckEditor
{
    public function __construct(private readonly DeckValidator $validator) {}

    /**
     * @param  array<string, mixed>  $manifest  the current deck manifest
     * @param  list<array<string, mixed>>  $operations
     * @return array{0: array<string, mixed>|null, 1: string|null} [next manifest, error]
     */
    public function apply(array $manifest, array $operations, ?string $name = null, ?string $theme = null): array
    {
        $slides = array_values((array) ($manifest['slides'] ?? []));

        foreach (array_values($operations) as $n => $op) {
            if (! is_array($op)) {
                return [null, "operations.{$n}: must be an object {op, index, slide?, to?}."];
            }
            $kind = $op['op'] ?? null;
            $index = $op['index'] ?? null;
            if (! is_int($index) || $index < 0) {
                return [null, "operations.{$n}: `index` must be a non-negative integer."];
            }
            $count = count($slides);

            switch ($kind) {
                case 'replace':
                    if ($index >= $count) {
                        return [null, "operations.{$n}: replace index {$index} is out of range (deck has {$count} slides)."];
                    }
                    if (! is_array($op['slide'] ?? null)) {
                        return [null, "operations.{$n}: replace requires a `slide` object."];
                    }
                    $slides[$index] = $op['slide'];
                    break;

                case 'insert':
                    if ($index > $count) {
                        return [null, "operations.{$n}: insert index {$index} is out of range (deck has {$count} slides; use {$count} to append)."];
                    }
                    if (! is_array($op['slide'] ?? null)) {
                        return [null, "operations.{$n}: insert requires a `slide` object."];
                    }
                    array_splice($slides, $index, 0, [$op['slide']]);
                    break;

                case 'remove':
                    if ($index >= $count) {
                        return [null, "operations.{$n}: remove index {$index} is out of range (deck has {$count} slides)."];
                    }
                    array_splice($slides, $index, 1);
                    break;

                case 'move':
                    $to = $op['to'] ?? null;
                    if (! is_int($to) || $to < 0) {
                        return [null, "operations.{$n}: move requires a non-negative `to`."];
                    }
                    if ($index >= $count || $to >= $count) {
                        return [null, "operations.{$n}: move indexes out of range (deck has {$count} slides)."];
                    }
                    [$moved] = array_splice($slides, $index, 1);
                    array_splice($slides, $to, 0, [$moved]);
                    break;

                default:
                    return [null, "operations.{$n}: op must be replace, insert, remove or move."];
            }
        }

        if ($slides === []) {
            return [null, 'The operations would leave the deck without slides.'];
        }

        $next = [
            'title' => $name ?? $manifest['title'] ?? 'Presentation',
            'theme' => $theme ?? $manifest['theme'] ?? 'executive',
            'slides' => $slides,
        ];

        $errors = $this->validator->validate($next);
        if ($errors !== []) {
            return [null, "The edited deck is invalid — nothing was saved. Fix these and retry:\n- ".implode("\n- ", $errors)];
        }

        return [$next, null];
    }

    /**
     * Persist the deck AND record the version-history snapshot (Living Decks).
     * Versioning is best-effort inside DeckVersioner — a history hiccup never
     * breaks the write.
     *
     * @param  array<string, mixed>  $manifest  a validated manifest
     * @param  array<string, mixed>|null  $resolved  pass when already resolved to skip a second pass
     */
    public function persist(
        Document $deck,
        array $manifest,
        ?User $actor = null,
        string $cause = 'edit',
        ?string $summary = null,
        ?array $resolved = null,
    ): void {
        $deck->update([
            'name' => (string) $manifest['title'],
            'body' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'metadata' => array_merge((array) $deck->metadata, [
                'theme' => (string) ($manifest['theme'] ?? 'executive'),
                'slide_count' => count((array) $manifest['slides']),
            ]),
        ]);

        app(DeckVersioner::class)->record($deck, $manifest, $cause, $actor, $summary, $resolved);
    }
}
