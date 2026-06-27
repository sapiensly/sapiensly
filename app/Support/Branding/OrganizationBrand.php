<?php

namespace App\Support\Branding;

/**
 * The organization Brandbook as an immutable value object: logo, icon, colours
 * and font centralized on the organization. It is the single source of truth that
 * every customizable surface inherits, and it owns the mapping from the canonical
 * brand vocabulary to each surface's own (an App's `settings.accent`, a Chatbot's
 * `appearance.primary_color`, …) so that mapping lives in ONE place.
 *
 * Inheritance is "fill the gaps": each apply* method only sets a surface value the
 * surface left unset, so a per-view override always wins over the brand default.
 */
final class OrganizationBrand
{
    public const FONTS = ['sans', 'serif', 'rounded', 'mono'];

    public const THEMES = ['light', 'dark'];

    public function __construct(
        public readonly ?string $logoUrl = null,
        public readonly ?string $iconUrl = null,
        public readonly ?string $iconEmoji = null,
        public readonly ?string $primaryColor = null,
        public readonly ?string $backgroundColor = null,
        public readonly ?string $textColor = null,
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
            primaryColor: self::hex($data['primary_color'] ?? null),
            backgroundColor: self::hex($data['background_color'] ?? null),
            textColor: self::hex($data['text_color'] ?? null),
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
            'primary_color' => $this->primaryColor,
            'background_color' => $this->backgroundColor,
            'text_color' => $this->textColor,
            'font' => $this->font,
            'theme' => $this->theme,
        ];
    }

    public function isEmpty(): bool
    {
        return array_filter($this->toArray(), fn ($v) => $v !== null) === [];
    }

    /**
     * Fill an App manifest `settings` block with the brand where the app left a
     * value unset (the app's own choices win). Maps brand → app vocabulary:
     * primaryColor → accent, logoUrl → brand.logo.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function applyToAppSettings(array $settings): array
    {
        if ($this->primaryColor !== null && empty($settings['accent'])) {
            $settings['accent'] = $this->primaryColor;
        }
        if ($this->font !== null && empty($settings['font'])) {
            $settings['font'] = $this->font;
        }
        if ($this->theme !== null && empty($settings['theme'])) {
            $settings['theme'] = $this->theme;
        }
        if ($this->logoUrl !== null) {
            $brand = $settings['brand'] ?? [];
            if (empty($brand['logo'])) {
                $brand['logo'] = $this->logoUrl;
                $settings['brand'] = $brand;
            }
        }

        return $settings;
    }

    /**
     * Fill a Chatbot widget `appearance` block with the brand where it left a
     * value at its built-in default. Maps brand → widget vocabulary.
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

        $fill('primary_color', $this->primaryColor);
        $fill('background_color', $this->backgroundColor);
        $fill('text_color', $this->textColor);
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
