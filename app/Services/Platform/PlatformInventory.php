<?php

namespace App\Services\Platform;

use App\Models\Agent;
use App\Models\App;
use App\Models\Chatbot;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiUsageReport;
use App\Support\Tenancy\Schemas;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cross-organization counts and rollups for the platform suite.
 *
 * Two different rules apply depending on where a table lives, and confusing
 * them is how a "platform total" silently becomes "the caller's own rows":
 *   - PLATFORM tables (users, organizations, apps, agents, chatbots) carry no
 *     RLS. Ordinary Eloquent already sees every row; nothing special needed.
 *   - TENANT tables (records, documents, conversations, the usage ledger) are
 *     RLS-protected and the request is scoped to ONE organization. Counting
 *     them platform-wide must go through the owner connection, exactly as
 *     {@see AiUsageReport::platformWide()} does.
 */
class PlatformInventory
{
    /**
     * Headline platform counts.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'organizations' => Organization::query()->count(),
            'users' => User::query()->count(),
            'users_blocked' => User::query()->whereNotNull('blocked_at')->count(),
            'users_unverified' => User::query()->whereNull('email_verified_at')->count(),
            'users_without_two_factor' => User::query()->whereNull('two_factor_confirmed_at')->count(),
            'apps' => App::query()->count(),
            'agents' => Agent::query()->count(),
            'chatbots' => Chatbot::query()->count(),
            'records' => $this->tenantCount('records'),
            'documents' => $this->tenantCount('documents'),
            'knowledge_bases' => $this->tenantCount('knowledge_bases'),
            'widget_conversations' => $this->tenantCount('widget_conversations'),
        ];
    }

    /**
     * Rows in a tenant table across EVERY organization. Returns -1 when the
     * count could not be taken, so a caller can tell "none" from "unknown".
     */
    public function tenantCount(string $table, ?string $organizationId = null): int
    {
        if (! in_array($table, Schemas::tenantTables(), true)) {
            return -1;
        }

        try {
            $query = DB::connection('pgsql')->table(Schemas::qualify($table));

            if ($organizationId !== null) {
                $query->where('organization_id', $organizationId);
            }

            return (int) $query->count();
        } catch (Throwable) {
            return -1;
        }
    }

    /**
     * Per-organization row counts for a tenant table, keyed by organization id.
     *
     * @return array<string, int>
     */
    public function tenantCountsByOrganization(string $table): array
    {
        if (! in_array($table, Schemas::tenantTables(), true)) {
            return [];
        }

        try {
            return DB::connection('pgsql')
                ->table(Schemas::qualify($table))
                ->whereNotNull('organization_id')
                ->selectRaw('organization_id, count(*) as aggregate')
                ->groupBy('organization_id')
                ->pluck('aggregate', 'organization_id')
                ->map(fn ($count) => (int) $count)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Growth over a trailing window: new organizations and users per day.
     *
     * @return array{organizations: array<string, int>, users: array<string, int>}
     */
    public function growth(int $days = 30): array
    {
        $since = now()->subDays(max(1, $days))->startOfDay();

        return [
            'organizations' => $this->dailyCounts(Organization::query()->getQuery(), $since),
            'users' => $this->dailyCounts(User::query()->getQuery(), $since),
        ];
    }

    /**
     * @param  Builder  $query
     * @return array<string, int>
     */
    private function dailyCounts($query, \DateTimeInterface $since): array
    {
        try {
            return $query
                ->where('created_at', '>=', $since)
                ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day, count(*) as aggregate")
                ->groupBy('day')
                ->orderBy('day')
                ->pluck('aggregate', 'day')
                ->map(fn ($count) => (int) $count)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
