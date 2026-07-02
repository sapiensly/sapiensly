<?php

namespace App\Jobs;

use App\Enums\DocumentType;
use App\Events\Slides\SlideBuilderComplete;
use App\Jobs\Middleware\EstablishTenantContext;
use App\Models\Document;
use App\Models\User;
use App\Services\Slides\DeckDataResolver;
use App\Services\Slides\DeckEditor;
use App\Services\Slides\DeckNarrator;
use App\Services\Slides\DeckVersioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One Living-Deck refresh: re-resolve the deck's live data bindings and, only
 * when the data actually changed, snapshot a new version — optionally letting
 * the AI narrator fix data-dependent prose and write the "what changed"
 * summary for the history. A no-change refresh costs a few aggregate queries
 * and writes nothing.
 */
class RefreshDeckJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 280;

    public int $tries = 1;

    public function __construct(
        public string $documentId,
        public ?string $organizationId,
        public int $userId,
        public string $cause = 'scheduled_refresh',
    ) {}

    public function viaQueue(): string
    {
        return 'ai';
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [EstablishTenantContext::fromOwner($this->organizationId, $this->userId)];
    }

    public function handle(
        DeckDataResolver $resolver,
        DeckVersioner $versioner,
        DeckEditor $editor,
        DeckNarrator $narrator,
    ): void {
        $user = User::find($this->userId);
        $deck = $user !== null
            ? Document::forAccountContext($user)->where('type', DocumentType::Deck)->find($this->documentId)
            : null;

        if ($user === null || $deck === null) {
            Log::warning('RefreshDeckJob: deck or user disappeared', [
                'document_id' => $this->documentId,
            ]);

            return;
        }

        $manifest = json_decode((string) $deck->body, true);
        if (! is_array($manifest)) {
            return;
        }

        $owner = $deck->user ?? $user;
        $resolved = $resolver->resolve($manifest, $owner);
        $digest = $versioner->digest($resolved);
        $latest = $versioner->latest($deck);

        $this->touchRefreshedAt($deck);

        if ($latest !== null && $versioner->digestsEqual($latest->data_digest, $digest)) {
            Log::info('Deck refresh: no data change', ['document_id' => $deck->id]);

            return;
        }

        // The data moved. Let the narrator fix stale prose + write the change
        // summary (opt-out per deck; strictly best-effort).
        $summary = null;
        $narrativeOn = (bool) (($deck->metadata['refresh']['narrative'] ?? true));
        if ($narrativeOn && $latest !== null) {
            $result = $narrator->narrate((array) $latest->data_digest, $digest, $manifest, $owner);
            $summary = $result['summary'];

            if ($result['operations'] !== []) {
                [$next, $error] = $editor->apply($manifest, $result['operations']);
                if ($next !== null) {
                    $manifest = $next;
                    $resolved = $resolver->resolve($manifest, $owner);
                } else {
                    Log::info('Deck refresh: narrator ops rejected', [
                        'document_id' => $deck->id,
                        'error' => $error,
                    ]);
                }
            }
        }

        $editor->persist($deck, $manifest, null, $this->cause, $summary, $resolved);
        $this->touchRefreshedAt($deck->refresh());

        // If the owner has the Builder open, update the canvas in place.
        try {
            SlideBuilderComplete::dispatch(
                $deck->id,
                'refresh-'.strtolower((string) Str::ulid()),
                $summary ?? '',
                $manifest,
                $resolved,
                $deck->name,
            );
        } catch (\Throwable) {
            // Best-effort.
        }

        Log::info('Deck refreshed', [
            'document_id' => $deck->id,
            'cause' => $this->cause,
            'summary' => $summary !== null,
        ]);
    }

    private function touchRefreshedAt(Document $deck): void
    {
        $metadata = (array) $deck->metadata;
        $metadata['refresh'] = array_merge((array) ($metadata['refresh'] ?? []), [
            'last_refreshed_at' => now()->toIso8601String(),
        ]);
        $deck->update(['metadata' => $metadata]);
    }
}
