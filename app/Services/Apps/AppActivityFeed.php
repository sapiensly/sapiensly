<?php

namespace App\Services\Apps;

use App\Models\App;
use App\Models\AppUserRole;
use App\Models\AppVersion;
use App\Models\RecordEvent;
use App\Models\WorkflowRun;
use Illuminate\Support\Collection;

/**
 * Everything that has happened to an app, in one list.
 *
 * Read, not written. Three of the four things worth knowing are already stored
 * with their actor and their timestamp — a version knows who saved it, a role
 * grant knows who gave it, a workflow run knows what set it off — so copying
 * them into a second table would double the storage and give the two copies
 * somewhere to disagree.
 *
 * And it would be actively wrong for one of them: activity retention deletes
 * old rows, and app versions ARE the rollback history. A policy that quietly
 * pruned them would take somebody's way back with it. Merging at read time
 * keeps each source on the lifecycle it actually needs — record events expire
 * on the tenant's retention, versions do not expire at all.
 *
 * The sources live on two connections, so this is four bounded queries and an
 * in-memory merge rather than a join. At a page's worth of rows that is the
 * cheaper shape anyway.
 */
class AppActivityFeed
{
    /** Rows taken from each source before merging. */
    private const PER_SOURCE = 50;

    /**
     * @return list<array{at: string|null, kind: string, actor: string|null, summary: string, detail: string|null, event?: string}>
     */
    public function for(App $app, int $limit = 60): array
    {
        $entries = new Collection([
            ...$this->records($app),
            ...$this->versions($app),
            ...$this->grants($app),
            ...$this->automation($app),
        ]);

        return $entries
            ->sortByDesc('at')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(App $app): array
    {
        return RecordEvent::query()
            ->where('app_id', $app->id)
            ->orderByDesc('created_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (RecordEvent $e): array => [
                'at' => $e->created_at?->toIso8601String(),
                'kind' => $e->kind === RecordEvent::KIND_COMMENT ? 'comment' : 'record',
                'actor' => $e->actor_name,
                // The VERB, not a sentence. A phrase built here would be
                // English inside an app written in Spanish — the same fault
                // this codebase has now fixed in the filter bar, the runtime's
                // empty states and the chart captions.
                'event' => $e->kind,
                'summary' => $e->kind === RecordEvent::KIND_COMMENT
                    ? (string) $e->body
                    : '',
                'detail' => $e->changes === null
                    ? null
                    : implode(', ', array_map(
                        fn (array $c): string => (string) ($c['label'] ?? $c['field'] ?? '?'),
                        $e->changes,
                    )),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function versions(App $app): array
    {
        return AppVersion::query()
            ->where('app_id', $app->id)
            ->with('createdBy:id,name')
            ->orderByDesc('version_number')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (AppVersion $v): array => [
                'at' => $v->created_at?->toIso8601String(),
                'kind' => 'structure',
                'actor' => $v->createdBy?->name,
                'summary' => 'v'.$v->version_number,
                'detail' => $v->change_summary,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grants(App $app): array
    {
        return AppUserRole::query()
            ->where('app_id', $app->id)
            ->with(['assignedUser:id,name', 'grantedBy:id,name'])
            ->orderByDesc('created_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (AppUserRole $g): array => [
                'at' => $g->created_at?->toIso8601String(),
                'kind' => 'access',
                'actor' => $g->grantedBy?->name,
                'summary' => ($g->assignedUser?->name ?? 'Someone').' → '.$g->role_slug,
                'detail' => null,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function automation(App $app): array
    {
        return WorkflowRun::query()
            ->where('app_id', $app->id)
            ->orderByDesc('created_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (WorkflowRun $r): array => [
                'at' => $r->created_at?->toIso8601String(),
                'kind' => 'automation',
                'actor' => null,
                'summary' => (string) $r->trigger_type,
                // A failure is the reason anybody opens this list, so it is the
                // detail rather than something to go and look up elsewhere.
                'detail' => $r->status === 'failed' ? (string) $r->error : (string) $r->status,
            ])
            ->all();
    }
}
