<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * One time a build was told it got something wrong.
 *
 * Append-only, and nothing reads it back into a prompt — see the migration for
 * why it exists. Tenant data (it quotes the request and the app's own names),
 * so it lives on the tenant connection under RLS.
 */
class BuildFinding extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    /** propose_change refused the ops: the model believed something untrue. */
    public const SIGNAL_PATCH_REJECTED = 'patch_rejected';

    /** The patch applied, but the validator warned about how it was built. */
    public const SIGNAL_DESIGN_SMELL = 'design_smell';

    /** The closing review's verdict: `missing` or `unrequested`. */
    public const SIGNAL_CRITIC = 'critic';

    /** Asked for and absent. */
    public const CODE_MISSING = 'missing';

    /** Present and never asked for. */
    public const CODE_UNREQUESTED = 'unrequested';

    protected $fillable = [
        'organization_id',
        'user_id',
        'app_id',
        'conversation_id',
        'model',
        'signal',
        'code',
        'path',
        'at',
        'detail',
    ];

    public static function getIdPrefix(): string
    {
        return 'bfd';
    }
}
