<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesPlatformConnection;
use App\Services\Platform\PlatformAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded platform-administration write. Append-only by convention: the
 * suite writes rows and reads them back, nothing updates or deletes them.
 *
 * @see PlatformAudit
 */
class PlatformAuditLog extends Model
{
    use HasPrefixedUlid;
    use UsesPlatformConnection;

    protected $table = 'platform_audit_log';

    public const RESULT_OK = 'ok';

    public const RESULT_REFUSED = 'refused';

    public const RESULT_FAILED = 'failed';

    protected $fillable = [
        'actor_user_id',
        'actor_email',
        'organization_id',
        'action',
        'target_type',
        'target_id',
        'target_label',
        'result',
        'summary',
        'meta',
        'ip',
        'channel',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'audit';
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
