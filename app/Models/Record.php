<?php

namespace App\Models;

use App\Console\Commands\PruneTrashedRecords;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\RecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Record extends Model
{
    /** @use HasFactory<RecordFactory> */
    use HasFactory, HasPrefixedUlid;

    /**
     * Deleting a record puts it in the trash; it is removed for good by
     * {@see PruneTrashedRecords} once the window has
     * passed, or by somebody emptying the trash on purpose.
     *
     * The trait is the point: every read of records in this codebase goes
     * through Eloquent, so the global scope excludes trashed rows from lists,
     * counts, charts, rollups and relation expansion at once. A `deleted_at`
     * column filtered by hand would have to be remembered in each of them, and
     * the one that forgot would quietly resurrect deleted data in a total.
     */
    use SoftDeletes;

    use UsesTenantConnection;

    /**
     * Query-time relation expansion, keyed by relation field id. belongs_to →
     * the related record `{id, data}` or null; has_many → `{items: [{id, data}],
     * count, truncated}`. Transient: a declared property (so Eloquent's attribute
     * magic is bypassed) that is never persisted — populated by
     * RecordQueryService::query() when the query block requests `expand`.
     *
     * @var array<string, array<string, mixed>|null>
     */
    public array $expanded = [];

    protected $fillable = [
        'environment',
        'organization_id',
        'user_id',
        'app_id',
        'object_definition_id',
        'data',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'rec';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
