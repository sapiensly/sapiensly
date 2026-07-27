<?php

namespace App\Services\Landing;

use App\Http\Middleware\ValidateWidgetOrigin;
use App\Models\App;
use App\Models\CustomDomain;
use Illuminate\Support\Facades\Cache;

/**
 * The origins a chatbot is reachable from because one of the organization's own
 * published landings serves it.
 *
 * The tempting shortcut — "when a landing publishes, append its origin to the
 * chatbot's allowed_origins" — is a trap: {@see ValidateWidgetOrigin} treats an
 * EMPTY list as "allow every origin", so writing the first entry into a bot that
 * had none would silently lock out every external site already embedding it.
 * Deriving the list instead means the tenant's own configuration is never
 * rewritten by a different module, and a landing that is unpublished or deleted
 * stops being an allowed origin the moment it stops existing.
 *
 * Note the same trap survives the move to runtime unless the caller is careful:
 * these origins only ever WIDEN a list the tenant already restricted. A bot with
 * no configured list stays open to everything, published landings or not — the
 * middleware short-circuits before it asks for this at all.
 *
 * Reads the denormalized `apps.chatbot_id` (indexed) rather than scanning
 * manifests — this runs on widget requests.
 */
class ChatbotLandingOrigins
{
    /** Short: a publish should take effect in seconds, not on a deploy. */
    private const TTL_SECONDS = 60;

    /**
     * Origins ("https://host") of every PUBLISHED landing bound to this chatbot:
     * the platform host that serves /l/{slug}, plus any active custom domain.
     *
     * Platform-level data keyed by chatbot id — no tenant-derived value is
     * cached here, so the shared cache is the right one (a per-tenant key would
     * buy nothing and TenantCache has no scope on a widget request anyway).
     *
     * @return list<string>
     */
    public function for(string $chatbotId): array
    {
        return Cache::remember(
            'chatbot-landing-origins:'.$chatbotId,
            self::TTL_SECONDS,
            fn (): array => $this->resolve($chatbotId),
        );
    }

    /**
     * @return list<string>
     */
    private function resolve(string $chatbotId): array
    {
        $apps = App::query()
            ->where('chatbot_id', $chatbotId)
            ->whereNotNull('published_at')
            ->whereNotNull('public_slug')
            ->get(['id']);

        if ($apps->isEmpty()) {
            return [];
        }

        // The platform origin serves every /l/{slug}, so one entry covers them all.
        $origins = [];
        $platform = parse_url((string) config('app.url'), PHP_URL_SCHEME).'://'
            .parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($platform !== '://') {
            $origins[] = strtolower($platform);
        }

        foreach (CustomDomain::query()->whereIn('app_id', $apps->pluck('id'))->get() as $domain) {
            if ($domain->hostname !== null && $domain->hostname !== '') {
                $origins[] = 'https://'.strtolower($domain->hostname);
            }
        }

        return array_values(array_unique($origins));
    }

    /** Drop the memo for a chatbot — call when a landing publishes or unpublishes. */
    public function forget(?string $chatbotId): void
    {
        if ($chatbotId !== null && $chatbotId !== '') {
            Cache::forget('chatbot-landing-origins:'.$chatbotId);
        }
    }
}
