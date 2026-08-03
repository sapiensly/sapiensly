<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\Record;
use App\Models\RecordEvent;
use App\Models\User;

/**
 * Writes what happened to a record.
 *
 * Every entry is best-effort: a trail is a courtesy, and losing one must never
 * cost somebody the change they were making. A create that succeeded and then
 * threw because its history could not be written would be a far worse bug than
 * a missing line in a list.
 *
 * What is stored is deliberately mixed: the field's LABEL as it read at the
 * time (so a trail survives the field being renamed or dropped) and the raw
 * VALUES (so it stays a truthful record of what was written). Turning a stored
 * value into something readable — a select's option label — is the reader's
 * job, against whatever the manifest says today.
 */
class RecordTrail
{
    public function __construct(private readonly ActivityRetention $retention) {}

    /** Fields nobody wants in a trail: the app works them out for itself. */
    private const DERIVED = ['rollup', 'lookup', 'formula'];

    public function created(App $app, array $manifest, Record $record, ?User $user): void
    {
        $this->write($app, $record, RecordEvent::KIND_CREATED, $user);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function updated(App $app, array $manifest, Record $record, array $before, array $after, ?User $user): void
    {
        $changes = $this->diff($manifest, $record, $before, $after);

        // Nothing actually moved — a save that changed no value is not an
        // event, and a trail full of them is a trail nobody scrolls.
        if ($changes === []) {
            return;
        }

        $this->write($app, $record, RecordEvent::KIND_UPDATED, $user, changes: $changes);
    }

    public function deleted(App $app, Record $record, ?User $user): void
    {
        $this->write($app, $record, RecordEvent::KIND_DELETED, $user);
    }

    public function restored(App $app, Record $record, ?User $user): void
    {
        $this->write($app, $record, RecordEvent::KIND_RESTORED, $user);
    }

    /**
     * Emptied from the trash.
     *
     * Written BEFORE the row goes, like a delete — and it is the one entry that
     * has to survive it, because after this there is nothing else left to say
     * the record ever existed.
     */
    public function purged(App $app, Record $record, ?User $user): void
    {
        $this->write($app, $record, RecordEvent::KIND_PURGED, $user);
    }

    public function comment(App $app, Record $record, string $body, ?User $user): ?RecordEvent
    {
        $body = trim($body);

        return $body === ''
            ? null
            : $this->write($app, $record, RecordEvent::KIND_COMMENT, $user, body: $body);
    }

    /**
     * @param  list<array<string, mixed>>|null  $changes
     */
    private function write(
        App $app,
        Record $record,
        string $kind,
        ?User $user,
        ?string $body = null,
        ?array $changes = null,
    ): ?RecordEvent {
        // Off is off: nothing is written. The saving is in not writing, not in
        // writing and pruning later — and an organisation that never asked for
        // a record of who did what should not have one accumulating quietly
        // until somebody notices the table.
        if (! $this->retention->isEnabled($app)) {
            return null;
        }

        try {
            return RecordEvent::create([
                'organization_id' => $app->organization_id,
                'app_id' => $app->id,
                'record_id' => $record->id,
                'object_definition_id' => $record->object_definition_id,
                'kind' => $kind,
                'actor_user_id' => $user?->id,
                // Captured, not joined: a person can leave the organisation and
                // the trail still has to say who did it.
                'actor_name' => $user?->name,
                'body' => $body,
                'changes' => $changes,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * What moved between two versions of a record's data.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{field: string, label: string, from: mixed, to: mixed}>
     */
    private function diff(array $manifest, Record $record, array $before, array $after): array
    {
        $fields = [];
        foreach ($manifest['objects'] ?? [] as $object) {
            if (($object['id'] ?? null) === $record->object_definition_id) {
                foreach ($object['fields'] ?? [] as $field) {
                    $fields[(string) ($field['slug'] ?? '')] = $field;
                }
                break;
            }
        }

        $changes = [];
        foreach ($after as $slug => $value) {
            $field = $fields[$slug] ?? null;

            // A field the app computes is not something a person changed.
            if ($field !== null && in_array($field['type'] ?? '', self::DERIVED, true)) {
                continue;
            }

            $was = $before[$slug] ?? null;
            if ($this->same($was, $value)) {
                continue;
            }

            $changes[] = [
                'field' => $slug,
                'label' => (string) ($field['name'] ?? $slug),
                'from' => $was,
                'to' => $value,
            ];
        }

        return $changes;
    }

    /**
     * Whether two stored values are the same thing.
     *
     * Loose on purpose at the edges: a number that arrives as "12" and is
     * stored as 12 did not change, and neither did a field that went from
     * absent to empty string. Recording those would fill the trail with events
     * nobody caused.
     */
    private function same(mixed $a, mixed $b): bool
    {
        if ($a === null && ($b === null || $b === '')) {
            return true;
        }
        if ($b === null && $a === '') {
            return true;
        }
        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }

        return $a === $b;
    }
}
