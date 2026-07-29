<?php

namespace App\Services\Site;

use App\Facades\TenantCache;
use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Models\User;
use App\Services\Branding\BrandProposalService;
use App\Services\Context\ContextProposalService;
use App\Support\Context\OrganizationContext;
use App\Support\Draft\DraftDiff;
use App\Support\Site\SiteFetch;
use App\Support\Site\SiteUrl;

/**
 * One reading of an organization's website, two books.
 *
 * The Brandbook and the Contextbook both answer "who is this organization?" from
 * the same home page, and they used to ask separately: the admin typed the URL
 * on one page, then typed it again on the other, and the site was downloaded
 * twice — plus a model call each time. This is the single entry point behind
 * both settings pages, so the URL is typed once and the page is read once.
 *
 * It still only ever PROPOSES. Everything comes back as a {@see DraftDiff}
 * against what is stored, so a field the organization already filled is reported
 * as a conflict and nothing is written until a human saves the form. That
 * contract is the reason the diff is recomputed on every call rather than cached
 * with the draft: what is stored can change between two looks at the same site.
 */
class SiteImportService
{
    /**
     * How long a drafted Contextbook stays reusable. The point is the second
     * book: whichever settings page the admin opens next re-runs the import and
     * gets the same draft back instantly, without a second model call the
     * organization would be billed for. Short enough that editing the website
     * and re-importing does what the user expects.
     */
    private const DRAFT_TTL_SECONDS = 1800;

    /** Cache key holding what was last imported, for the "you already read this" banner. */
    private const LAST_KEY = 'site_import.last';

    public function __construct(
        private readonly SiteProfileFetcher $sites,
        private readonly BrandProposalService $brands,
        private readonly ContextProposalService $contexts,
    ) {}

    /**
     * Read the site and propose both books from it.
     *
     * @return array{
     *     url: string|null,
     *     read: bool,
     *     reason: string,
     *     reused: bool,
     *     imported_at: string,
     *     brand: array<string, mixed>,
     *     context: array<string, mixed>,
     * }
     */
    public function import(Organization $organization, ?string $website, string $brief = '', ?User $user = null): array
    {
        $brief = trim($brief);
        $url = SiteUrl::normalize($website);
        $fetch = $url === null
            ? SiteFetch::failed(trim((string) $website) === '' ? SiteFetch::NO_URL : SiteFetch::INVALID_URL)
            : $this->sites->read($url);

        $reused = false;
        $draft = $this->cachedDraft($url, $brief);

        if ($draft === null) {
            $draft = $this->contexts->draft($fetch->profile, $brief, $user);
            $this->rememberDraft($url, $brief, $draft);
        } else {
            $reused = true;
        }

        $importedAt = now()->toIso8601String();

        // Remembered even when the read failed: the banner on the other page is
        // there to save the admin from typing the URL again, and a URL that did
        // not work the first time is exactly the one they will want to retype.
        TenantCache::put(self::LAST_KEY, [
            'url' => $url,
            'brief' => $brief,
            'at' => $importedAt,
        ], self::DRAFT_TTL_SECONDS);

        return [
            'url' => $url,
            'read' => $fetch->successful(),
            'reason' => $fetch->reason,
            'reused' => $reused,
            'imported_at' => $importedAt,
            'brand' => $this->brands->fromProfile($organization, $fetch->profile),
            'context' => $this->contextResult($organization, $draft, $fetch),
        ];
    }

    /**
     * What was last imported in this organization, or null. Feeds the banner
     * that offers the other book the site the admin already read.
     *
     * @return array{url: string|null, brief: string, at: string}|null
     */
    public function lastImport(): ?array
    {
        $last = TenantCache::get(self::LAST_KEY);

        return is_array($last) && isset($last['at']) ? $last : null;
    }

    /**
     * The drafted profile against what is stored, plus the block the models will
     * actually read and what it costs — the same three things the Contextbook
     * form shows for a hand-typed entry.
     *
     * @param  array{profile: array<string, mixed>, sources: list<string>, generated: bool}  $draft
     * @return array<string, mixed>
     */
    private function contextResult(Organization $organization, array $draft, SiteFetch $fetch): array
    {
        $stored = OrganizationAiContext::firstOrNew(['organization_id' => $organization->id])->profile ?? [];
        $diff = DraftDiff::between($stored, $draft['profile']);
        $context = OrganizationContext::fromArray($draft['profile']);

        return [
            'profile' => $draft['profile'],
            'sources' => $draft['sources'],
            'generated' => $draft['generated'],
            // The page opened but had no words on it — a client-rendered site.
            // Without saying so, an empty draft next to "drafted from: your
            // website" reads as the feature being broken.
            'site_has_prose' => $fetch->successful() && $fetch->profile->hasProse(),
            'diff' => $diff->toArray(),
            'has_conflicts' => $diff->hasConflicts(),
            'preview' => $context->promptBlock($organization->name),
            'tokens' => $context->estimatedTokens($organization->name),
        ];
    }

    /**
     * @return array{profile: array<string, mixed>, sources: list<string>, generated: bool}|null
     */
    private function cachedDraft(?string $url, string $brief): ?array
    {
        $key = self::draftKey($url, $brief);
        if ($key === null) {
            return null;
        }

        $cached = TenantCache::get($key);

        return is_array($cached) && is_array($cached['profile'] ?? null) ? $cached : null;
    }

    /**
     * @param  array{profile: array<string, mixed>, sources: list<string>, generated: bool}  $draft
     */
    private function rememberDraft(?string $url, string $brief, array $draft): void
    {
        $key = self::draftKey($url, $brief);

        // A draft the model failed to produce is not worth replaying for half an
        // hour — the retry is the whole point of clicking the button again.
        if ($key === null || ! $draft['generated']) {
            return;
        }

        TenantCache::put($key, $draft, self::DRAFT_TTL_SECONDS);
    }

    /** Keyed by exactly what the draft was built from; null when there is nothing to key on. */
    private static function draftKey(?string $url, string $brief): ?string
    {
        if ($url === null && $brief === '') {
            return null;
        }

        return 'site_import.draft:'.sha1(($url ?? '').'|'.$brief);
    }
}
