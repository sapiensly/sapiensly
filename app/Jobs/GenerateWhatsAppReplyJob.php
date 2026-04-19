<?php

namespace App\Jobs;

use App\Enums\ConversationStatus;
use App\Models\WhatsAppConversation;
use App\Services\WhatsApp\WhatsAppReplyOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Queued entry point into `WhatsAppReplyOrchestrator`. A short-lived cache
 * lock serialises reply generation per conversation so a burst of rapid
 * inbound messages from the same contact does not produce parallel replies
 * against stale context.
 */
class GenerateWhatsAppReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [15, 60];

    public function __construct(
        public string $conversationId,
    ) {
        $this->onQueue('whatsapp-outbound');
    }

    public function handle(WhatsAppReplyOrchestrator $orchestrator): void
    {
        $conversation = WhatsAppConversation::query()->find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        if ($conversation->status->suppressesAutoReply()) {
            Log::channel('whatsapp')->info('orchestrator.skipped_suppressed', [
                'conversation_id' => $conversation->id,
                'status' => $conversation->status->value,
            ]);

            return;
        }

        $lock = Cache::lock('wa_reply_'.$conversation->id, 45);

        if (! $lock->block(15)) {
            Log::channel('whatsapp')->warning('orchestrator.lock_timeout', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        try {
            $fresh = $conversation->fresh();
            if ($fresh === null || $fresh->status->suppressesAutoReply()) {
                return;
            }

            if ($fresh->status === ConversationStatus::Pending) {
                $fresh->forceFill(['status' => ConversationStatus::Open])->save();
            }

            $orchestrator->reply($fresh);
        } finally {
            $lock->release();
        }
    }
}
