<?php

namespace App\Jobs;

use App\Enums\DocumentType;
use App\Events\Slides\SlideBuilderError;
use App\Models\Document;
use App\Models\User;
use App\Services\Slides\SlideBuilderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a Slide Builder chat turn in the background; the service streams
 * SlideBuilderChunk / Complete / Error broadcasts the Builder UI consumes via
 * Reverb. Routed to the `ai` queue like every other LLM turn.
 */
#[Queue('ai')]
class RunSlideBuilderJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 280;

    public int $tries = 1;

    public function __construct(
        public string $documentId,
        public int $userId,
        public string $messageId,
        public string $userText,
    ) {}

    public function handle(SlideBuilderService $service): void
    {
        $user = User::find($this->userId);
        $deck = $user !== null
            ? Document::forAccountContext($user)->where('type', DocumentType::Deck)->find($this->documentId)
            : null;

        if ($user === null || $deck === null) {
            Log::warning('RunSlideBuilderJob: deck or user disappeared', [
                'document_id' => $this->documentId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $service->runTurn($deck, $user, $this->messageId, $this->userText);
    }

    /**
     * The runner itself died (timeout) — unfreeze the composer.
     */
    public function failed(?Throwable $e): void
    {
        try {
            broadcast(new SlideBuilderError(
                $this->documentId,
                $this->messageId,
                'The turn timed out or crashed: '.($e?->getMessage() ?? 'unknown error'),
            ));
        } catch (Throwable) {
            // Reverb down too — the UI's stale-stream guard recovers on reload.
        }
    }
}
