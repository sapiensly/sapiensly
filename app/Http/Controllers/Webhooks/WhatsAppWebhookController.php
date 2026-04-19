<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Public surface for WhatsApp inbound events. Two methods only:
 *   - verify()  : Meta's one-time challenge (GET).
 *   - receive() : event POSTs — must always 200 then enqueue (Meta retries for
 *                 72h on any non-200, so processing errors cannot bubble).
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppConnection $connection): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        if ($mode !== 'subscribe' || ! hash_equals((string) $connection->webhook_verify_token, $token)) {
            Log::channel('whatsapp')->warning('webhook.verify_rejected', [
                'connection_id' => $connection->id,
                'mode' => $mode,
            ]);

            return response('forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppConnection $connection): Response
    {
        $connection->update(['last_webhook_received_at' => now()]);

        ProcessWhatsAppWebhookJob::dispatch($connection->id, $request->json()->all())
            ->onQueue('whatsapp-webhooks');

        // Acknowledge fast — processing runs async.
        return response('', 200);
    }
}
