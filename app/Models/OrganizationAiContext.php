<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use App\Support\Context\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's Contextbook: the minimum business knowledge every model
 * interaction inside that organization carries. Platform config, 1:1 with an
 * organization, mirroring {@see OrganizationAiBudget}.
 *
 * `profile` holds the structured fields; `compiled_prompt` holds the rendered
 * block, materialized on write by {@see self::recompile()} so the read path is
 * a single indexed lookup and never pays to re-render.
 */
class OrganizationAiContext extends Model
{
    use UsesPlatformConnection;

    /** Injection is on by default, so a freshly instantiated row behaves like a saved one. */
    protected $attributes = [
        'enabled' => true,
    ];

    protected $fillable = [
        'organization_id',
        'profile',
        'compiled_prompt',
        'compiled_tokens',
        'enabled',
        'updated_by_id',
    ];

    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'compiled_tokens' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The stored profile as a normalized value object (empty when unset). */
    public function context(): OrganizationContext
    {
        return OrganizationContext::fromArray($this->profile);
    }

    /**
     * Re-render `compiled_prompt` / `compiled_tokens` from the current profile.
     * Call after every profile change — the compiled columns are the only thing
     * the prompt path reads, so a profile written without this is invisible.
     */
    public function recompile(): static
    {
        $block = $this->context()->promptBlock($this->organization?->name);

        $this->compiled_prompt = $block === '' ? null : $block;
        $this->compiled_tokens = $block === '' ? null : OrganizationContext::tokensFor($block);

        return $this;
    }

    /**
     * The block to inject, or null when there is nothing to say (unset profile)
     * or the org switched injection off.
     */
    public function injectableBlock(): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $block = trim((string) $this->compiled_prompt);

        return $block === '' ? null : $block;
    }
}
