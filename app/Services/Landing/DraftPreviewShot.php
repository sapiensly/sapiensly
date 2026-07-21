<?php

namespace App\Services\Landing;

use App\Events\Builder\BuilderDraftShotRequested;
use App\Models\App;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use App\Support\Css\ScopedAppCss;
use App\Support\Tenancy\TenantCache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\StoredImage;

/**
 * Stage 2 of the design director's eyes: a live screenshot of the CURRENT
 * DRAFT, captured by the open builder UI mid-turn.
 *
 * Stage 1 (LatestPreviewShot) only sees APPLIED versions — during a first
 * authoring turn there is no applied version yet, so every critique round ran
 * text-only exactly when pixels matter most. This closes that gap with a
 * cache-rendezvous round trip:
 *
 *   1. The critique tool stashes the draft's landing page (blocks + scoped
 *      css + settings) in the tenant cache under a nonce and broadcasts
 *      BuilderDraftShotRequested on the conversation channel.
 *   2. The builder UI fetches the payload over HTTP (that GET doubles as the
 *      "a browser is listening" ACK), renders it off-screen with the same
 *      preview renderer, settles motion, and POSTs back a JPEG.
 *   3. capture() polls the cache: no ack within ~4s → give up fast (headless /
 *      closed-tab turns pay seconds, not the full wait); acked → wait for the
 *      shot, materialise it to a local temp file and hand back a StoredImage.
 *
 * The rendezvous is cache (Redis), not tenant storage, so it works on keyless
 * local envs where LatestPreviewShot can't. Everything is best-effort: any
 * failure returns null and the critique degrades exactly like Stage 1.
 */
class DraftPreviewShot
{
    /** No ack inside this window ⇒ no browser is listening; bail fast. */
    public int $ackTimeoutMs = 4000;

    /** Render + capture + upload budget once a browser has acked. */
    public int $shotTimeoutMs = 20000;

    public int $pollIntervalMs = 250;

    private const TTL_SECONDS = 180;

    public function __construct(private readonly TenantCache $cache) {}

    /**
     * Ask the open builder UI for a screenshot of the draft. Null when the
     * manifest has no landing page, no browser answers, or anything fails.
     *
     * @param  array<string, mixed>  $manifest  the DRAFT manifest being critiqued
     */
    public function capture(App $app, string $conversationId, array $manifest): ?StoredImage
    {
        try {
            $payload = $this->payload($app, $manifest);
            if ($payload === null) {
                return null;
            }

            $nonce = strtolower((string) Str::ulid());
            $cache = $this->scoped($app);
            $cache->put($this->key('payload', $nonce), $payload, self::TTL_SECONDS);

            BuilderDraftShotRequested::dispatch($conversationId, $nonce);

            if (! $this->await(fn (): bool => $cache->has($this->key('ack', $nonce)), $this->ackTimeoutMs)) {
                return null;
            }
            if (! $this->await(fn (): bool => $cache->has($this->key('jpeg', $nonce)), $this->shotTimeoutMs)) {
                return null;
            }

            $bytes = base64_decode((string) $cache->pull($this->key('jpeg', $nonce)), true);
            if ($bytes === false || $bytes === '') {
                return null;
            }

            $path = 'tmp/draft-shots/'.$nonce.'.jpg';
            Storage::disk('local')->put($path, $bytes);

            return new StoredImage($path, 'local');
        } catch (\Throwable) {
            // Eyes are optional — a failed capture must never break the turn.
            return null;
        }
    }

    /**
     * The builder UI fetching the draft to render. Marks the ack so capture()
     * knows a browser is on it. Null for an unknown/expired nonce.
     *
     * @return array<string, mixed>|null
     */
    public function claim(App $app, string $nonce): ?array
    {
        $cache = $this->scoped($app);
        $payload = $cache->get($this->key('payload', $nonce));
        if (! is_array($payload)) {
            return null;
        }
        $cache->put($this->key('ack', $nonce), true, self::TTL_SECONDS);

        return $payload;
    }

    /**
     * The builder UI posting the captured JPEG back. Only accepted for a nonce
     * that was actually requested (and claimed), so the endpoint can't be used
     * to plant arbitrary blobs.
     */
    public function storeShot(App $app, string $nonce, string $jpegBytes): bool
    {
        $cache = $this->scoped($app);
        if (! $cache->has($this->key('ack', $nonce))) {
            return false;
        }
        $cache->put($this->key('jpeg', $nonce), base64_encode($jpegBytes), self::TTL_SECONDS);

        return true;
    }

    /** Delete the materialised temp file once the critique has consumed it. */
    public function cleanup(?StoredImage $shot): void
    {
        if ($shot === null) {
            return;
        }
        try {
            if (str_starts_with($shot->path, 'tmp/draft-shots/')) {
                Storage::disk('local')->delete($shot->path);
            }
        } catch (\Throwable) {
            // Best-effort.
        }
    }

    /**
     * The draft's landing page in the same shape the builder preview renders
     * (mirrors AppBuilderController's preview payload): page blocks, settings
     * with the live palette, and the author CSS pre-scoped to the surface.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function payload(App $app, array $manifest): ?array
    {
        if (($manifest['settings']['surface'] ?? null) !== 'landing') {
            return null;
        }
        $pages = array_values(array_filter(
            $manifest['pages'] ?? [],
            fn ($p): bool => is_array($p) && is_array($p['blocks'] ?? null) && $p['blocks'] !== [],
        ));
        if ($pages === []) {
            return null;
        }

        $settings = $app->organization !== null
            ? $app->organization->brandbook()->applyToAppSettings($manifest['settings'] ?? [])
            : ($manifest['settings'] ?? []);
        $settings['palette'] = ColorPalette::fromAccent(
            $settings['accent'] ?? OrganizationBrand::DEFAULT_ACCENT,
            (string) ($settings['palette_mode'] ?? 'brand'),
        );

        return [
            'page' => $pages[0],
            'objects' => $manifest['objects'] ?? [],
            'settings' => $settings,
            'custom_css' => ScopedAppCss::compile($settings['custom_css'] ?? null),
        ];
    }

    private function scoped(App $app): TenantCache
    {
        return $this->cache->forOwner($app->organization_id, $app->user_id);
    }

    private function key(string $part, string $nonce): string
    {
        return "draft_shot:{$part}:{$nonce}";
    }

    /** @param  callable(): bool  $ready */
    private function await(callable $ready, int $timeoutMs): bool
    {
        $deadline = hrtime(true) + $timeoutMs * 1_000_000;
        while (true) {
            if ($ready()) {
                return true;
            }
            if (hrtime(true) >= $deadline) {
                return false;
            }
            usleep($this->pollIntervalMs * 1000);
        }
    }
}
