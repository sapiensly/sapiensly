<?php

namespace App\Services\Context;

use App\Ai\ChatAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Branding\PaletteProposalService;
use App\Services\Site\SiteProfileFetcher;
use App\Support\Context\OrganizationContext;
use App\Support\Draft\DraftDiff;
use App\Support\Site\SiteProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * Drafts an organization Contextbook from material the organization already has
 * — its website and whatever the admin can describe in a sentence — so the form
 * is never a blank page nobody fills in. Mirrors
 * {@see PaletteProposalService}: the model proposes, a
 * human edits and confirms; nothing here writes to the database.
 *
 * The draft is normalized through {@see OrganizationContext::fromArray()}, the
 * same gate a hand-typed form goes through, so a hallucinated field shape or an
 * over-long value cannot reach storage.
 */
class ContextProposalService
{
    /** Characters of fetched page text handed to the model. */
    private const MAX_SOURCE_CHARS = 12000;

    private const SYSTEM = <<<'SYS'
        You extract a company's factual profile from the material given to you, for a "Contextbook" that will be prepended to every AI interaction inside that company.
        Respond with ONLY a single minified JSON object — no markdown, no code fences, no commentary — using exactly this schema (every key optional; OMIT a key you cannot support with the material):
        {"descriptor":string,"industry":string,"size":string,"audience":string,"geographies":[string],"currency":string,"language":string,"formality":"formal"|"neutral"|"casual","glossary":[{"term":string,"meaning":string}],"offerings":[{"name":string,"description":string}],"links":[{"label":string,"url":string}]}
        RULES:
        - NEVER invent. If the material does not say it, omit the key. A short honest profile beats a padded one; this text is billed on every AI call the company makes.
        - NEVER write a placeholder as a value ("unknown", "not specified", "no especificado", "N/A", "-"). A key you cannot answer is OMITTED, not filled with a shrug.
        - descriptor: ONE sentence, max 240 chars, on what the company actually does.
        - offerings: max 6 real products/services with a one-line description each. A product or service NAME belongs here and ONLY here.
        - glossary: ONLY terms whose MEANING inside this company differs from the everyday one — internal jargon, acronyms, a word the company uses to mean something specific. NEVER repeat a name you already listed under offerings: describing a product twice doubles what the company pays on every single call. If a term's only explanation is "it is one of their products", it does not belong here.
        - language: the BCP-47 tag of the language the material is written in (e.g. es-MX, en). formality: how the material itself reads.
        - links: only URLs that literally appear in the material, each pointing somewhere DIFFERENT. Never list the home page several times under different labels; if the home page is the only URL you have, return one link or none.
        - Write every value in the SAME LANGUAGE as the material.
        SYS;

    /**
     * Values a model writes when it means "I don't know". The prompt forbids
     * them; this is the net under it, applied ONLY to model output — a human who
     * types "N/A" into the form meant to.
     *
     * @var list<string>
     */
    private const PLACEHOLDER_VALUES = [
        'unknown', 'not specified', 'unspecified', 'not available', 'n/a', 'na', 'none', '-', '--', '?',
        'no especificado', 'no especificada', 'sin especificar', 'no disponible', 'ninguno', 'ninguna',
        'não especificado', 'desconhecido',
    ];

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
        private readonly SiteProfileFetcher $sites,
    ) {}

    /**
     * Draft a Contextbook. Returns the normalized profile plus what it was built
     * from, so the UI can tell the user why a field is empty — and a
     * {@see DraftDiff} against what is already stored, because a draft never
     * overwrites what a human wrote without being asked.
     *
     * @param  array<string, mixed>  $current  the stored profile this draft would land on
     * @return array{
     *     profile: array<string, mixed>,
     *     sources: list<string>,
     *     generated: bool,
     *     diff: list<array{field: string, status: string, current: mixed, proposed: mixed}>,
     *     has_conflicts: bool,
     * }
     */
    public function propose(?string $website, string $brief = '', ?User $user = null, array $current = []): array
    {
        $draft = $this->draft($this->sites->fetch($website), $brief, $user);

        return self::result(
            OrganizationContext::fromArray($draft['profile']),
            $draft['sources'],
            $draft['generated'],
            $current,
        );
    }

    /**
     * The drafting half on its own: material in, normalized profile out, with no
     * comparison against anything stored.
     *
     * Split out for the unified site import, which needs the two halves apart —
     * the model call is the slow, billed step and is worth reusing across both
     * books, while the diff has to be recomputed against whatever is stored at
     * the moment the user looks at it.
     *
     * @return array{profile: array<string, mixed>, sources: list<string>, generated: bool}
     */
    public function draft(?SiteProfile $site, string $brief = '', ?User $user = null): array
    {
        $sources = [];
        $material = '';

        $brief = trim($brief);
        if ($brief !== '') {
            $sources[] = 'brief';
            $material .= "What the administrator says about the organization:\n".Str::limit($brief, 2000)."\n\n";
        }

        // A page with a title and no words is not material. Sending it anyway
        // asks a model to profile a company from its name — which is either an
        // empty answer we paid for, or an invented one.
        if ($site !== null && $site->hasProse()) {
            $sources[] = 'website';
            $material .= self::siteMaterial($site);
        }

        if ($material === '') {
            return ['profile' => OrganizationContext::fromArray(null)->toArray(), 'sources' => [], 'generated' => false];
        }

        $decoded = $this->askModel($material, $user);

        return [
            'profile' => OrganizationContext::fromArray(
                is_array($decoded) ? self::sanitize($decoded, $site) : null,
            )->toArray(),
            'sources' => $sources,
            'generated' => is_array($decoded),
        ];
    }

    /**
     * Clean up what the model returned before it becomes a proposal. The prompt
     * asks for all of this; these are the deterministic nets under it, and they
     * run ONLY on model output — a human typing into the form is never touched.
     *
     * Each rule is here because a live draft did it: a "Size: no especificado",
     * a glossary that repeated every product already listed under offerings, and
     * five links all pointing at the home page under invented labels.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private static function sanitize(array $decoded, ?SiteProfile $site): array
    {
        foreach ($decoded as $key => $value) {
            if (is_string($value) && in_array(strtolower(trim($value)), self::PLACEHOLDER_VALUES, true)) {
                unset($decoded[$key]);
            }
        }

        // A product name explained twice is billed twice, on every call. The
        // offering wins: that is where a name with a description belongs.
        if (is_array($decoded['glossary'] ?? null)) {
            $offeringNames = collect($decoded['offerings'] ?? [])
                ->filter(fn ($o) => is_array($o) && is_string($o['name'] ?? null))
                ->map(fn ($o) => mb_strtolower(trim($o['name'])))
                ->all();

            $decoded['glossary'] = array_values(array_filter(
                $decoded['glossary'],
                fn ($entry) => ! is_array($entry)
                    || ! is_string($entry['term'] ?? null)
                    || ! in_array(mb_strtolower(trim($entry['term'])), $offeringNames, true),
            ));
        }

        if (is_array($decoded['links'] ?? null)) {
            $decoded['links'] = self::distinctLinks($decoded['links'], $site);
        }

        return $decoded;
    }

    /**
     * One link per destination, and never the bare home page dressed up as
     * several different pages.
     *
     * @param  array<mixed>  $links
     * @return list<mixed>
     */
    private static function distinctLinks(array $links, ?SiteProfile $site): array
    {
        $home = $site !== null ? self::canonicalUrl($site->url) : null;

        $seen = [];
        $kept = [];

        foreach ($links as $link) {
            if (! is_array($link) || ! is_string($link['url'] ?? null)) {
                continue;
            }

            $url = self::canonicalUrl($link['url']);
            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $kept[] = $link;
        }

        // The home page is worth listing only when it is the ONLY thing we have;
        // alongside real destinations it adds nothing an agent doesn't know.
        if ($home !== null && count($kept) > 1) {
            $kept = array_values(array_filter(
                $kept,
                fn ($link) => self::canonicalUrl($link['url']) !== $home,
            ));
        }

        return $kept;
    }

    /** Scheme- and trailing-slash-insensitive form, so two spellings of one page collapse. */
    private static function canonicalUrl(string $url): string
    {
        $url = strtolower(trim($url));
        $url = (string) preg_replace('#^https?://#', '', $url);

        return rtrim($url, '/');
    }

    /**
     * @param  list<string>  $sources
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private static function result(OrganizationContext $context, array $sources, bool $generated, array $current): array
    {
        $profile = $context->toArray();
        $diff = DraftDiff::between($current, $profile);

        return [
            'profile' => $profile,
            'sources' => $sources,
            'generated' => $generated,
            'diff' => $diff->toArray(),
            'has_conflicts' => $diff->hasConflicts(),
        ];
    }

    /**
     * The site as prompt material. The title and meta description lead because
     * they are the site's own one-line self-description — usually a better
     * `descriptor` than anything buried in the body copy.
     */
    private static function siteMaterial(SiteProfile $site): string
    {
        $material = "Text of {$site->url}:\n";

        if ($site->title !== null) {
            $material .= "Page title: {$site->title}\n";
        }
        if ($site->description !== null) {
            $material .= "Meta description: {$site->description}\n";
        }

        return $material.Str::limit((string) $site->text, self::MAX_SOURCE_CHARS, '');
    }

    private function askModel(string $material, ?User $user): mixed
    {
        $model = $this->aiDefaults->model('summary_short');
        $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;

        try {
            $agent = new ChatAgent(instructions: self::SYSTEM, messages: [], tools: []);
            $response = $agent->prompt(
                $material,
                provider: $provider,
                model: $model,
                timeout: (int) config('ai.request_timeout', 180),
            );

            return self::extractJson((string) ($response->text ?? ''));
        } catch (Throwable $e) {
            Log::warning('ContextProposalService: the draft could not be generated', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** Pull the first JSON object out of a model reply, tolerating fences and prose. */
    private static function extractJson(string $text): mixed
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        try {
            return json_decode(substr($text, $start, $end - $start + 1), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }
}
