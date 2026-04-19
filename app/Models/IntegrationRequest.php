<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationRequest extends Model
{
    use HasFactory, HasPrefixedUlid;

    protected $fillable = [
        'integration_id',
        'user_id',
        'name',
        'description',
        'folder',
        'method',
        'path',
        'query_params',
        'headers',
        'body_type',
        'body_content',
        'timeout_ms',
        'follow_redirects',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'query_params' => 'array',
            'headers' => 'array',
            'timeout_ms' => 'integer',
            'follow_redirects' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'intreq';
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(IntegrationExecution::class)->latest();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
