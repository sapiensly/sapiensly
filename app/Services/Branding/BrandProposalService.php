<?php

namespace App\Services\Branding;

use App\Models\Organization;
use App\Services\Context\ContextProposalService;
use App\Services\Site\SiteProfileFetcher;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use App\Support\Draft\DraftDiff;
use App\Support\Site\SiteProfile;

/**
 * Reads an organization's website and proposes a Brandbook from it — the other
 * consumer of {@see SiteProfileFetcher}, alongside
 * {@see ContextProposalService}. One fetch, two books.
 *
 * It proposes; it never writes. Everything comes back as a {@see DraftDiff}
 * against the stored Brandbook, so a field the organization already filled is
 * reported as a conflict and stays untouched until a human says otherwise.
 *
 * Asset URLs are proposed as they appear on the site — remote. Nothing is copied
 * into tenant storage at this stage: importing bytes the user is about to reject
 * would be writing before asking, in a slower and dirtier way.
 */
class BrandProposalService
{
    /**
     * Families whose name does not announce what they are. Anything not listed
     * and not self-describing falls through to `sans`, which is the safe default
     * — a serif mislabelled sans is a smaller sin than the reverse.
     *
     * @var array<string, string>
     */
    private const FAMILY_KIND = [
        'georgia' => 'serif', 'times' => 'serif', 'times new roman' => 'serif',
        'garamond' => 'serif', 'baskerville' => 'serif', 'palatino' => 'serif',
        'fraunces' => 'serif', 'playfair display' => 'serif', 'merriweather' => 'serif',
        'lora' => 'serif', 'spectral' => 'serif', 'crimson text' => 'serif',
        'cambria' => 'serif', 'bitter' => 'serif', 'arvo' => 'serif',
        'quicksand' => 'rounded', 'nunito' => 'rounded', 'comfortaa' => 'rounded',
        'fredoka' => 'rounded', 'baloo' => 'rounded', 'varela round' => 'rounded',
        'consolas' => 'mono', 'menlo' => 'mono', 'monaco' => 'mono', 'courier' => 'mono',
        'courier new' => 'mono', 'inconsolata' => 'mono',
    ];

    /**
     * Below this saturation a colour is a grey, and a grey accent reads as a
     * disabled control rather than a button. Calibrated against the slate greys
     * sites actually declare: a Tailwind gray-500 (#6b7280) lands at 0.16 and
     * must fall on the reject side, while real brand colours sit above 0.6.
     */
    private const MIN_SATURATION = 0.25;

    /** Outside this luminance band an accent disappears into a white or black surface. */
    private const MIN_LUMINANCE = 0.06;

    private const MAX_LUMINANCE = 0.85;

    public function __construct(private readonly SiteProfileFetcher $sites) {}

    /**
     * Propose a Brandbook from a website. `website` defaults to nothing — the
     * Brandbook has no URL of its own, so the caller passes the organization's
     * site (typically the Contextbook's).
     *
     * @return array{
     *     read: bool,
     *     source: string|null,
     *     proposal: array<string, mixed>,
     *     diff: list<array{field: string, status: string, current: mixed, proposed: mixed}>,
     *     has_conflicts: bool,
     *     palette: array<string, mixed>|null,
     *     notes: list<string>,
     * }
     */
    public function propose(Organization $organization, ?string $website): array
    {
        $site = $this->sites->fetch($website);

        if ($site === null || ! $site->hasBrandSignals()) {
            return [
                'read' => $site !== null,
                'source' => $site?->url,
                'proposal' => [],
                'diff' => [],
                'has_conflicts' => false,
                'palette' => null,
                'notes' => [$site === null
                    ? 'The site could not be read.'
                    : 'The site carries no brand signals we can use (no icon, logo, theme colour or webfont).'],
            ];
        }

        $notes = [];
        $proposal = array_filter([
            'icon_url' => $site->iconUrl,
            'logo_url' => $site->logoUrl,
            'accent_color' => $this->accentFrom($site, $notes),
            'font' => self::fontFrom($site->fonts),
            'theme' => $site->colorScheme,
        ], fn ($value) => $value !== null);

        $diff = DraftDiff::between($organization->brandbook()->toArray(), $proposal);

        return [
            'read' => true,
            'source' => $site->url,
            'proposal' => $proposal,
            'diff' => $diff->toArray(),
            'has_conflicts' => $diff->hasConflicts(),
            // The palette the proposed accent would produce, so the user compares
            // the real thing rather than a hex code.
            'palette' => isset($proposal['accent_color'])
                ? ColorPalette::fromAccent($proposal['accent_color'])
                : null,
            'notes' => $notes,
        ];
    }

    /**
     * The site's declared theme colour, but only when it can actually serve as
     * the colour of a primary button. A brand's own near-white or grey is a real
     * brand colour and a useless accent; saying so is more useful than proposing
     * it and letting the user discover it later.
     *
     * @param  list<string>  $notes
     */
    private function accentFrom(SiteProfile $site, array &$notes): ?string
    {
        if ($site->themeColor === null) {
            return null;
        }

        if (self::isUsableAccent($site->themeColor)) {
            return $site->themeColor;
        }

        $notes[] = "The site declares {$site->themeColor} as its theme colour, but it cannot carry buttons and "
            .'links on both light and dark surfaces. Generate accent proposals instead.';

        return null;
    }

    /** Saturated enough to read as an action, and neither near-white nor near-black. */
    public static function isUsableAccent(string $hex): bool
    {
        [$r, $g, $b] = self::rgb($hex);

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $luminance = 0.299 * $r + 0.587 * $g + 0.114 * $b;
        $saturation = $max === 0.0 ? 0.0 : ($max - $min) / $max;

        return $saturation >= self::MIN_SATURATION
            && $luminance >= self::MIN_LUMINANCE
            && $luminance <= self::MAX_LUMINANCE;
    }

    /**
     * Map the site's font stack onto the four families the Brandbook offers.
     * Self-describing names win over the lookup table: a family called "…Mono"
     * is mono whether or not anyone listed it.
     *
     * @param  list<string>  $families
     */
    public static function fontFrom(array $families): ?string
    {
        foreach ($families as $family) {
            $name = strtolower(trim($family));

            $kind = match (true) {
                str_contains($name, 'mono') => 'mono',
                str_contains($name, 'round') => 'rounded',
                str_contains($name, 'slab') => 'serif',
                str_contains($name, 'sans') => 'sans',
                str_contains($name, 'serif') => 'serif',
                default => self::FAMILY_KIND[$name] ?? null,
            };

            if ($kind !== null && in_array($kind, OrganizationBrand::FONTS, true)) {
                return $kind;
            }
        }

        // A named family we do not recognize is still a real font choice, and
        // sans is what the overwhelming majority of them are.
        return $families === [] ? null : 'sans';
    }

    /**
     * @return array{float, float, float}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (float) hexdec(substr($hex, 0, 2)) / 255,
            (float) hexdec(substr($hex, 2, 2)) / 255,
            (float) hexdec(substr($hex, 4, 2)) / 255,
        ];
    }
}
