<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * One AI model call: tokens, computed cost, and whether it ran on the org's own
 * key (`own`) or a platform/system key (`system`). Tenant data — RLS-scoped to
 * the owning organization. Append-only.
 */
class AiUsageEvent extends Model
{
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'user_id',
        'module',
        'driver',
        'model',
        'source',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'reasoning_tokens',
        'cost',
        'estimated',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'cost' => 'float',
            'estimated' => 'boolean',
        ];
    }
}
