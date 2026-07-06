<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * One AI model call billed to a platform/system provider key (the platform
 * pays). Platform/control-plane data — NOT RLS-scoped, so system calls without a
 * resolvable tenant context are still recorded. organization_id / user_id are
 * attribution-only and nullable. Append-only. The platform-wide system spend
 * meter reads this; the per-org meter reads {@see AiUsageEvent} instead.
 */
class SystemAiUsageEvent extends Model
{
    use UsesPlatformConnection;

    protected $fillable = [
        'organization_id',
        'user_id',
        'module',
        'app_id',
        'conversation_id',
        'driver',
        'model',
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
