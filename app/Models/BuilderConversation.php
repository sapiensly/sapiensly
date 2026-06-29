<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuilderConversation extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'app_id',
        'user_id',
        'status',
        'build_plan',
    ];

    protected function casts(): array
    {
        return [
            'build_plan' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'cnv';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(BuilderMessage::class, 'conversation_id')->orderBy('created_at');
    }
}
