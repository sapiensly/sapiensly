<?php

namespace App\Services\Slides;

use App\Models\DeckVersion;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Records the version history of a presentation ("Living Decks"). Each write
 * — an edit, a data refresh, a restore — snapshots the deck with its live
 * bindings resolved and BAKED, so any historical version opens faithfully
 * without querying the sources it was built from.
 *
 * Rapid consecutive edits by the same author coalesce into one rolling
 * version (a typing session is one version, not thirty), and history is
 * capped at KEEP_VERSIONS. Recording is best-effort by design: a versioning
 * hiccup must never break the edit or refresh that triggered it.
 */
class DeckVersioner
{
    /** Edits by the same author within this window update the last version in place. */
    private const COALESCE_MINUTES = 10;

    private const KEEP_VERSIONS = 50;

    public function __construct(private readonly DeckDataResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $manifest  the authored manifest (with bindings)
     * @param  array<string, mixed>|null  $resolved  pass when already resolved to skip a second pass
     */
    public function record(
        Document $deck,
        array $manifest,
        string $cause,
        ?User $actor = null,
        ?string $summary = null,
        ?array $resolved = null,
    ): ?DeckVersion {
        try {
            $owner = $deck->user;
            $resolved ??= $owner !== null
                ? $this->resolver->resolve($manifest, $owner)
                : $manifest;

            $digest = $this->digest($resolved);
            $latest = $this->latest($deck);

            // A typing session is one version: fold rapid same-author edits
            // into the newest version instead of stacking a row per keystroke
            // batch.
            if (
                $cause === 'edit'
                && $latest !== null
                && $latest->cause === 'edit'
                && $latest->created_by_user_id === $actor?->id
                && $latest->created_at !== null
                && $latest->created_at->gt(now()->subMinutes(self::COALESCE_MINUTES))
            ) {
                $latest->update([
                    'manifest' => $resolved,
                    'source_manifest' => $manifest,
                    'data_digest' => $digest,
                ]);

                return $latest;
            }

            $version = DeckVersion::create([
                'organization_id' => $deck->organization_id,
                'user_id' => $deck->user_id,
                'document_id' => $deck->id,
                'version_number' => ($latest?->version_number ?? 0) + 1,
                'cause' => $cause,
                'manifest' => $resolved,
                'source_manifest' => $manifest,
                'data_digest' => $digest,
                'change_summary' => $summary,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->prune($deck);

            return $version;
        } catch (\Throwable $e) {
            Log::warning('Deck versioning failed (continuing)', [
                'document_id' => $deck->id,
                'cause' => $cause,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function latest(Document $deck): ?DeckVersion
    {
        return DeckVersion::query()
            ->where('document_id', $deck->id)
            ->orderByDesc('version_number')
            ->first();
    }

    /**
     * Fingerprint of every LIVE binding's current values (charts with a
     * data_source, metric items with a value_source), keyed by slide position
     * + binding signature. Two digests being equal means a refresh found no
     * new data — no version is written.
     *
     * @param  array<string, mixed>  $resolved  a manifest with bindings resolved
     * @return array<string, mixed>
     */
    public function digest(array $resolved): array
    {
        $digest = [];

        foreach (array_values((array) ($resolved['slides'] ?? [])) as $i => $slide) {
            if (! is_array($slide)) {
                continue;
            }
            if (($slide['layout'] ?? null) === 'chart' && is_array($slide['data_source'] ?? null)) {
                $key = "s{$i}:chart:".$this->signature($slide['data_source']);
                $digest[$key] = [
                    'labels' => $slide['labels'] ?? [],
                    'series' => array_map(
                        fn ($s) => is_array($s) ? ($s['data'] ?? []) : [],
                        (array) ($slide['series'] ?? []),
                    ),
                ];
            }
            if (($slide['layout'] ?? null) === 'metrics') {
                foreach (array_values((array) ($slide['items'] ?? [])) as $j => $item) {
                    if (is_array($item) && is_array($item['value_source'] ?? null)) {
                        $key = "s{$i}:m{$j}:".$this->signature($item['value_source']);
                        $digest[$key] = $item['value'] ?? null;
                    }
                }
            }
        }

        return $digest;
    }

    /**
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     */
    public function digestsEqual(?array $a, ?array $b): bool
    {
        return $this->canonical($a ?? []) === $this->canonical($b ?? []);
    }

    /** @param array<string, mixed> $source */
    private function signature(array $source): string
    {
        ksort($source);

        return substr(md5((string) json_encode($source)), 0, 8);
    }

    /** @param array<string, mixed> $value */
    private function canonical(array $value): string
    {
        ksort($value);

        return (string) json_encode($value);
    }

    private function prune(Document $deck): void
    {
        $cutoff = DeckVersion::query()
            ->where('document_id', $deck->id)
            ->orderByDesc('version_number')
            ->skip(self::KEEP_VERSIONS - 1)
            ->value('version_number');

        if ($cutoff !== null) {
            DeckVersion::query()
                ->where('document_id', $deck->id)
                ->where('version_number', '<', $cutoff)
                ->delete();
        }
    }
}
