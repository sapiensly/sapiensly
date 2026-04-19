<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\CloudProviderService;
use App\Services\WhatsApp\WhatsAppProviderContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetch the binary payload for a media message announced by the webhook and
 * persist it on the tenant-resolved disk. Meta's media URLs expire in 5 minutes
 * so we run this on the high-priority `whatsapp-webhooks` queue.
 */
class DownloadWhatsAppMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 180];

    public function __construct(
        public string $messageId,
        public string $mediaId,
    ) {
        $this->onQueue('whatsapp-webhooks');
    }

    public function handle(
        WhatsAppProviderContract $provider,
        CloudProviderService $cloud,
    ): void {
        $message = WhatsAppMessage::query()->find($this->messageId);

        if ($message === null) {
            return;
        }

        $connection = $message->conversation?->channel?->whatsAppConnection;

        if ($connection === null) {
            Log::channel('whatsapp')->warning('media.missing_connection', [
                'message_id' => $message->id,
            ]);

            return;
        }

        try {
            $bytes = $provider->downloadMedia($connection, $this->mediaId);
        } catch (Throwable $e) {
            Log::channel('whatsapp')->warning('media.download_failed', [
                'message_id' => $message->id,
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $organization = $connection->channel?->organization;
        $disk = $cloud->diskForOrganizationOrFallback($organization?->id);

        $extension = $this->guessExtension($message->media_mime);
        $path = sprintf('whatsapp/%s/%s%s', $connection->id, $message->id, $extension);

        $disk->put($path, $bytes);

        $message->forceFill(['media_local_path' => $path])->save();
    }

    private function guessExtension(?string $mime): string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'image/gif' => '.gif',
            'audio/ogg', 'audio/ogg; codecs=opus' => '.ogg',
            'audio/mpeg' => '.mp3',
            'audio/mp4' => '.m4a',
            'video/mp4' => '.mp4',
            'video/3gpp' => '.3gp',
            'application/pdf' => '.pdf',
            default => '',
        };
    }
}
