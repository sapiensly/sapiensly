<?php

namespace App\Http\Controllers\Settings\Concerns;

use App\Models\Organization;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Validation\Rule;

/**
 * The Brandbook write path: what a form may submit, and what storing it means.
 *
 * It lives here rather than in the controller because the Brandbook is no longer
 * a screen of its own — it is one tab of the organization identity, saved in the
 * same request as the Contextbook. The rules and the merge are the same either
 * way, and two copies of them would be two places to forget a field.
 */
trait WritesBrandbook
{
    /**
     * The brand fields accepted from a form, in canonical (stored) vocabulary.
     * Doubles as the whitelist on write, so adding a field here is enough.
     *
     * @return array<string, mixed>
     */
    protected function brandRules(): array
    {
        return [
            'logo_url' => ['nullable', 'string', 'max:2000'],
            'icon_url' => ['nullable', 'string', 'max:2000'],
            'logo_dark_url' => ['nullable', 'string', 'max:2000'],
            'icon_dark_url' => ['nullable', 'string', 'max:2000'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_bg_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font' => ['nullable', Rule::in(OrganizationBrand::FONTS)],
            'theme' => ['nullable', Rule::in(OrganizationBrand::THEMES)],
        ];
    }

    /** Whether the submitted form carried anything about the Brandbook at all. */
    protected function submitsBrandbook(array $validated): bool
    {
        return array_intersect_key($validated, $this->brandRules()) !== [];
    }

    /**
     * Merge only the submitted keys over the stored brand, then normalize, so a
     * partial update leaves untouched fields intact and a cleared field clears.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function saveBrandbook(Organization $organization, array $validated): void
    {
        $incoming = array_intersect_key($validated, $this->brandRules());
        $merged = array_merge($organization->brand ?? [], $incoming);

        $organization->brand = OrganizationBrand::fromArray($merged)->toArray();
        $organization->save();
    }
}
