<?php

namespace App\Http\Middleware;

use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppProviderContract;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any inbound WhatsApp webhook whose X-Hub-Signature-256 does not
 * validate against the connection's app_secret. Must run before any parsing
 * (we rely on the raw request body for the HMAC).
 */
class VerifyWhatsAppSignature
{
    public function __construct(
        private WhatsAppProviderContract $provider,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $connection = $request->route('connection');

        if (! $connection instanceof WhatsAppConnection) {
            // Route hasn't resolved the model binding yet — bail safely.
            return response()->json(['error' => 'connection_missing'], 404);
        }

        if (! $this->provider->verifyWebhookSignature($connection, $request)) {
            Log::channel('whatsapp')->warning('webhook.signature_failed', [
                'connection_id' => $connection->id,
                'body_hash' => hash('sha256', $request->getContent()),
            ]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        return $next($request);
    }
}
