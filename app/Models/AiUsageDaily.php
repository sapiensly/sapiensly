<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-org/day/model/source rollup of ai_usage_events, written by the daily
 * aggregation command and read by the spend dashboards. Tenant data — RLS-scoped.
 */
class AiUsageDaily extends Model
{
    use UsesTenantConnection;

    protected $table = 'ai_usage_daily';

    protected $fillable = [
        'organization_id',
        'user_id',
        'date',
        'module',
        'driver',
        'model',
        'source',
        'calls',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'reasoning_tokens',
        'cost',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'calls' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'cost' => 'float',
        ];
    }
}
