<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * A CRM update drafted by capability #0001 (HubSpot post-call agent), awaiting
 * approval, plus its outcome once applied. Tenant runtime data (RLS): the effect
 * is *described* here and only executed through the gate — never written to the
 * system of record by drafting. See docs/capabilities/0001-hubspot-post-call-agent.md.
 */
class CrmUpdateProposal extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'capability_id',
        'call_id',
        'status',
        'target',
        'operation',
        'changes',
        'rationale',
        'confidence',
        'evidence',
        'call_snapshot',
        'source_fetched_at',
        'approver_id',
        'applied_at',
        'external_object_id',
        'error',
        'organization_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'target' => 'array',
            'changes' => 'array',
            'evidence' => 'array',
            'call_snapshot' => 'array',
            'confidence' => 'float',
            'source_fetched_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'cpro';
    }
}
