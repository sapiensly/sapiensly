<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Enums\WhatsAppContentType;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppProviderContract;
use App\Services\WhatsApp\WhatsAppProviderException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches a single pre-persisted WhatsAppMessage to the provider. Retries
 * are handled by Laravel's queue layer — the job itself is intentionally thin.
 * The `whatsapp-outbound` queue is scaled separately from the app's default
 * queue so high-volume sends don't starve user-facing work.
 */
class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * Exponential backoff in seconds between retries. 3 tries total.
     */
    public array $backoff = [10, 60, 300];

    public int $tries = 3;

    public function __construct(
        public readonly string $messageId,
    ) {
        $this->onQueue('whatsapp-outbound');
    }

    public function handle(WhatsAppProviderContract $provider): void
    {
        $message = WhatsAppMessage::with('conversation.channel.whatsAppConnection', 'conversation.contact')
            ->find($this->messageId);

        if (! $message) {
            Log::channel('whatsapp')->warning('outbound.message_missing', ['id' => $this->messageId]);

            return;
        }

        $connection = $message->conversation?->channel?->whatsAppConnection;
        $contact = $message->conversation?->contact;

        if (! $connection || ! $contact) {
            $this->markFailed($message, 0, 'missing_connection_or_contact');

            return;
        }

        if ($message->status === MessageStatus::Sent || $message->status === MessageStatus::Delivered
            || $message->status === MessageStatus::Read) {
            // Another worker already sent it; dedup.
            return;
        }

        try {
            $result = match ($message->content_type) {
                WhatsAppContentType::Text => $provider->sendText(
                    $connection,
                    $contact->identifier,
                    $message->content,
                ),
                WhatsAppContentType::Template => $this->sendTemplate($provider, $connection, $contact->identifier, $message),
                default => throw new \RuntimeException("Unsupported outbound content_type: {$message->content_type->value}"),
            };

            $message->update([
                'wamid' => $result['wamid'] ?? $message->wamid,
                'provider_message_id' => $result['provider_message_id'] ?? null,
                'status' => MessageStatus::Sent,
            ]);

            Log::channel('whatsapp')->info('outbound.sent', [
                'message_id' => $message->id,
                'wamid' => $result['wamid'] ?? null,
            ]);
        } catch (WhatsAppProviderException $e) {
            $this->handleProviderError($message, $e);
            // Re-throw on retryable errors so the queue backs off.
            if (! $e->isAuthError() && $this->attempts() < $this->tries) {
                throw $e;
            }
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('outbound.exception', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // queue handles retry/backoff
        }
    }

    public function failed(\Throwable $exception): void
    {
        $message = WhatsAppMessage::find($this->messageId);
        if ($message && $message->status->rank() < MessageStatus::Sent->rank()) {
            $this->markFailed($message, 0, $exception->getMessage());
        }
    }

    /**
     * @return array{wamid: ?string, provider_message_id: ?string}
     */
    private function sendTemplate(
        WhatsAppProviderContract $provider,
        WhatsAppConnection $connection,
        string $to,
        WhatsAppMessage $message,
    ): array {
        $template = WhatsAppTemplate::where('whatsapp_connection_id', $connection->id)
            ->where('name', $message->template_name)
            ->where('language', $message->template_language)
            ->firstOrFail();

        $components = $message->metadata['components'] ?? [];

        return $provider->sendTemplate($connection, $to, $template, $components);
    }

    private function handleProviderError(WhatsAppMessage $message, WhatsAppProviderException $e): void
    {
        $message->update([
            'error_code' => $e->providerErrorCode,
            'error_message' => substr($e->getMessage(), 0, 500),
            'status' => $this->attempts() >= $this->tries || $e->isAuthError()
                ? MessageStatus::Failed
                : MessageStatus::Pending,
        ]);
    }

    private function markFailed(WhatsAppMessage $message, int $code, string $error): void
    {
        $message->update([
            'status' => MessageStatus::Failed,
            'error_code' => $code,
            'error_message' => substr($error, 0, 500),
        ]);
    }
}
