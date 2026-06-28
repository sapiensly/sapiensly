<?php

namespace App\Support\Branding;

/**
 * The organization Brandbook as an immutable value object: logo, icon, a single
 * brand accent colour, and a font/theme centralized on the organization. It is the
 * single source of truth every customizable surface inherits, and it owns the
 * mapping from the canonical brand vocabulary to each surface's own (an App's
 * `settings.accent`, a Chatbot's `appearance.primary_color`, …) so that mapping
 * lives in ONE place.
 *
 * Inheritance is "fill the gaps": each apply* method only sets a surface value the
 * surface left unset, so a per-view override always wins over the brand default.
 */
final class OrganizationBrand
{
    public const FONTS = ['sans', 'serif', 'rounded', 'mono'];

    public const THEMES = ['light', 'dark'];

    /** The platform's default accent (the `--sp-accent-blue` token) — the brand's accent falls back to this. */
    public const DEFAULT_ACCENT = '#0096ff';

    public function __construct(
        public readonly ?string $logoUrl = null,
        public readonly ?string $iconUrl = null,
        public readonly ?string $iconEmoji = null,
        public readonly ?string $accentColor = null,
        public readonly ?string $logoBgColor = null,
        public readonly ?string $font = null,
        public readonly ?string $theme = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            logoUrl: self::str($data['logo_url'] ?? null),
            iconUrl: self::str($data['icon_url'] ?? null),
            iconEmoji: self::str($data['icon_emoji'] ?? null),
            accentColor: self::hex($data['accent_color'] ?? null),
            logoBgColor: self::hex($data['logo_bg_color'] ?? null),
            font: in_array($data['font'] ?? null, self::FONTS, true) ? $data['font'] : null,
            theme: in_array($data['theme'] ?? null, self::THEMES, true) ? $data['theme'] : null,
        );
    }

    /**
     * The stored shape: every key present, nulls for unset values, so a partial
     * update merges predictably.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'logo_url' => $this->logoUrl,
            'icon_url' => $this->iconUrl,
            'icon_emoji' => $this->iconEmoji,
            'accent_color' => $this->accentColor,
            'logo_bg_color' => $this->logoBgColor,
            'font' => $this->font,
            'theme' => $this->theme,
        ];
    }

    public function isEmpty(): bool
    {
        return array_filter($this->toArray(), fn ($v) => $v !== null) === [];
    }

    /** The accent the brand resolves to, falling back to the platform default. */
    public function effectiveAccent(): string
    {
        return $this->accentColor ?? self::DEFAULT_ACCENT;
    }

    /**
     * Fill an App manifest `settings` block with the brand where the app left a
     * value unset (the app's own choices win). Maps brand → app vocabulary:
     * accentColor → accent, logoUrl → brand.logo.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function applyToAppSettings(array $settings): array
    {
        if ($this->accentColor !== null && empty($settings['accent'])) {
            $settings['accent'] = $this->accentColor;
        }
        if ($this->font !== null && empty($settings['font'])) {
            $settings['font'] = $this->font;
        }
        if ($this->theme !== null && empty($settings['theme'])) {
            $settings['theme'] = $this->theme;
        }
        if ($this->logoUrl !== null || $this->logoBgColor !== null) {
            $brand = $settings['brand'] ?? [];
            if ($this->logoUrl !== null && empty($brand['logo'])) {
                $brand['logo'] = $this->logoUrl;
            }
            if ($this->logoBgColor !== null && empty($brand['header_bg'])) {
                $brand['header_bg'] = $this->logoBgColor;
            }
            $settings['brand'] = $brand;
        }

        return $settings;
    }

    /**
     * Fill a Chatbot widget `appearance` block with the brand where it left a
     * value at its built-in default. The brand owns only the accent (→ the
     * widget's primary_color) and the logo; the widget keeps its own
     * background/text colours.
     *
     * @param  array<string, mixed>  $appearance
     * @param  array<string, mixed>  $defaults  the widget's built-in defaults, so we only override values still at default
     * @return array<string, mixed>
     */
    public function applyToChatbotAppearance(array $appearance, array $defaults = []): array
    {
        $fill = function (string $key, ?string $value) use (&$appearance, $defaults): void {
            if ($value === null) {
                return;
            }
            $current = $appearance[$key] ?? null;
            if ($current === null || $current === '' || $current === ($defaults[$key] ?? null)) {
                $appearance[$key] = $value;
            }
        };

        $fill('primary_color', $this->accentColor);
        $fill('logo_url', $this->logoUrl);

        return $appearance;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? $value : null;
    }
}
