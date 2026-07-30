<?php

namespace App\Support\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile verification for the surfaces a stranger can write from
 * (the landing lead form, a public portal action). Shared so both doors are
 * guarded by the same code — a bot-check that exists in two copies is a
 * bot-check that will eventually only be fixed in one.
 */
final class Turnstile
{
    /**
     * Skipped entirely when no secret is configured (local/dev), so the
     * honeypot + throttle remain the floor everywhere.
     */
    public static function passes(Request $request, string $token): bool
    {
        $secret = (string) (config('services.turnstile.secret_key') ?? '');
        if ($secret === '') {
            return true;
        }
        if ($token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                ['secret' => $secret, 'response' => $token, 'remoteip' => $request->ip()],
            );

            return (bool) ($response->json('success') ?? false);
        } catch (\Throwable) {
            // Verification outage: fail OPEN with the honeypot + throttle floor
            // still in place — a lost submission costs more than a spam row.
            return true;
        }
    }
}
