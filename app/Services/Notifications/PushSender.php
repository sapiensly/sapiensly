<?php

namespace App\Services\Notifications;

use App\Models\App;
use App\Models\PushSubscription;
use App\Support\Push\WebPush;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telling a phone something while the app is closed.
 *
 * One person can be several browsers, so this addresses DEVICES and answers in
 * terms of people: a technician with a phone and a desktop counts as reached
 * when either one takes the message, and as unreachable only when they have no
 * device registered at all — which is the honest answer for somebody who never
 * allowed notifications.
 *
 * A subscription that the push service says is GONE is deleted here. That is
 * not tidiness: a browser that has been uninstalled, cleared or revoked keeps
 * its endpoint alive as a 404 for ever, and an app that keeps trying spends a
 * request per notification per dead device, permanently.
 */
class PushSender
{
    /**
     * @return array{sent: int, devices: int, failed: int}
     */
    public function sendToUser(App $app, int $userId, string $title, string $body, ?string $link): array
    {
        $subscriptions = PushSubscription::query()
            ->where('app_id', $app->id)
            ->where('user_id', $userId)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            if ($this->deliver($app, $subscription, $title, $body, $link)) {
                $sent++;

                continue;
            }

            $failed++;
        }

        return ['sent' => $sent, 'devices' => $subscriptions->count(), 'failed' => $failed];
    }

    /** Whether this app can send at all. False when nobody configured VAPID. */
    public function isConfigured(): bool
    {
        return is_string(config('push.vapid.public'))
            && is_string(config('push.vapid.private'))
            && config('push.vapid.public') !== ''
            && config('push.vapid.private') !== '';
    }

    private function deliver(
        App $app,
        PushSubscription $subscription,
        string $title,
        string $body,
        ?string $link,
    ): bool {
        try {
            // Everything the notification will say travels encrypted: a push
            // service is a third party that routes these and must not be able
            // to read the name of a customer or the address of a visit.
            $payload = (string) json_encode([
                'title' => $title,
                'body' => $body,
                'url' => $link,
                'app' => $app->slug,
            ], JSON_UNESCAPED_UNICODE);

            $encrypted = WebPush::encrypt($payload, $subscription->p256dh, $subscription->auth);

            $endpoint = (string) $subscription->endpoint;
            $origin = (string) parse_url($endpoint, PHP_URL_SCHEME).'://'.(string) parse_url($endpoint, PHP_URL_HOST);

            $response = Http::withHeaders([
                'Authorization' => WebPush::authorization(
                    $origin,
                    (string) config('push.vapid.subject'),
                    (string) config('push.vapid.private'),
                    (string) config('push.vapid.public'),
                ),
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                // Deliver now if you can, and give up in a quarter of an hour.
                // A job assignment that arrives tomorrow morning is worse than
                // one that never arrives: the person has already been called.
                'TTL' => '900',
                'Urgency' => 'high',
            ])
                ->timeout((int) config('push.timeout', 5))
                ->withBody($encrypted, 'application/octet-stream')
                ->post($endpoint);

            // 404/410 is the service saying this browser is gone for good.
            if (in_array($response->status(), [404, 410], true)) {
                $subscription->delete();

                return false;
            }

            return $response->successful();
        } catch (Throwable $e) {
            // The detail stays here: an endpoint is an address for somebody's
            // device and has no business in an app author's run log.
            Log::warning('push delivery failed', [
                'app_id' => $app->id,
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
