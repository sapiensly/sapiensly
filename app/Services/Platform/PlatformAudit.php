<?php

namespace App\Services\Platform;

use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records platform-administration writes to `platform_audit_log`.
 *
 * Every sysadmin MCP tool that changes something calls this, so "who turned off
 * email verification / rotated the Anthropic key / deleted that account" has an
 * answer that does not depend on anyone having tailed a log file.
 *
 * Two deliberate choices:
 *   - meta is SANITIZED before it is stored. Admin arguments routinely carry
 *     API keys; an audit trail that copies them turns one secret store into two.
 *   - a failed write never aborts the action it was recording. Losing the audit
 *     row is bad; leaving the platform half-administered because the log table
 *     was unreachable is worse. The failure itself goes to the daily log.
 */
class PlatformAudit
{
    /** Argument names whose values are replaced with a marker, matched loosely. */
    private const SECRET_PATTERNS = [
        'key', 'secret', 'password', 'token', 'credential', 'authorization', 'api_key',
    ];

    private const REDACTED = '[redacted]';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(
        string $action,
        ?User $actor = null,
        ?string $organizationId = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $targetLabel = null,
        string $result = PlatformAuditLog::RESULT_OK,
        ?string $summary = null,
        array $meta = [],
        string $channel = 'mcp',
    ): ?PlatformAuditLog {
        try {
            return PlatformAuditLog::create([
                'actor_user_id' => $actor?->id,
                'actor_email' => $actor?->email,
                'organization_id' => $organizationId ?? $actor?->organization_id,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'target_label' => $targetLabel,
                'result' => $result,
                'summary' => $summary,
                'meta' => $meta === [] ? null : $this->sanitize($meta),
                'ip' => request()?->ip(),
                'channel' => $channel,
            ]);
        } catch (Throwable $e) {
            Log::channel('daily')->error('platform_audit.write_failed', [
                'action' => $action,
                'actor_user_id' => $actor?->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Replace anything that looks like a credential with a marker, recursively.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function sanitize(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->looksSecret($key)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }

    private function looksSecret(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::SECRET_PATTERNS as $pattern) {
            if (str_contains($needle, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
