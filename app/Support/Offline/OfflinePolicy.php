<?php

namespace App\Support\Offline;

/**
 * What this app is allowed to leave on a device.
 *
 * Phases 1–3 made a built app work without a signal. The cost of that is real
 * and was not, until here, anybody's decision to make: a cached page is tenant
 * rows written to a phone's disk in the clear, and a queued write is somebody's
 * data sitting in IndexedDB until the signal comes back. For a work order that
 * is exactly the trade you want. For a payroll run it is not, and the person
 * who knows which is which is the one who built the app.
 *
 * So `settings.offline` says it, and this class is the one place that reads it.
 *
 * DEFAULT ON, deliberately. Offline shipped on for every app, and an app that
 * silently stopped working in the basement it was built for would be a worse
 * failure than the one this guards against. The opt-out is explicit and the
 * granularity is the OBJECT, because "salaries must not sit on a phone" is a
 * fact about data, not about a page — and stated about the data it keeps
 * holding when somebody adds a second page that shows it.
 */
class OfflinePolicy
{
    /**
     * @param  list<string>  $excludedObjectIds  Object ids that may never be cached or queued.
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly array $excludedObjectIds,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function for(array $manifest): self
    {
        $settings = $manifest['settings']['offline'] ?? [];
        $enabled = ($settings['enabled'] ?? true) !== false;

        if (! $enabled) {
            return new self(false, []);
        }

        $excluded = [];
        foreach ((array) ($settings['exclude_objects'] ?? []) as $slug) {
            foreach ($manifest['objects'] ?? [] as $object) {
                if (($object['slug'] ?? null) === $slug && isset($object['id'])) {
                    $excluded[] = (string) $object['id'];
                }
            }
        }

        return new self(true, array_values(array_unique($excluded)));
    }

    /**
     * Whether a page's response may be written to the device.
     *
     * A page is off-limits when anything on it reads an excluded object. Derived
     * rather than declared: an author marks the DATA sensitive once, and a page
     * added next month that happens to chart it is covered without anyone
     * remembering to say so.
     *
     * @param  array<string, mixed>|null  $page
     */
    public function mayCachePage(?array $page): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($page === null || $this->excludedObjectIds === []) {
            return $this->enabled;
        }

        return array_intersect($this->excludedObjectIds, self::objectIdsIn($page)) === [];
    }

    /**
     * Every object id anywhere under a node.
     *
     * Walks GENERICALLY rather than descending a list of known container keys.
     * A chart carries one per series, a tabs block hides them a level down, and
     * the list of places an `object_id` can appear is exactly the list that goes
     * stale — the same lesson `TechnicalWriter` learned by printing whatever a
     * node carries instead of an enumerated set.
     *
     * @return list<string>
     */
    public static function objectIdsIn(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];

        foreach ($node as $key => $value) {
            if ($key === 'object_id' && is_string($value)) {
                $found[] = $value;

                continue;
            }

            if (is_array($value)) {
                $found = array_merge($found, self::objectIdsIn($value));
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * What the runtime client needs to make the same decision.
     *
     * The server's `no-store` is what actually stops a page being cached — it
     * cannot be bypassed by a stale service worker, and that is why it is the
     * enforcement point. This is for the two things only the client can decide:
     * whether to hold a WRITE, and whether to hold the photo attached to it.
     *
     * @return array{enabled: bool, excluded_object_ids: list<string>}
     */
    public function toClient(): array
    {
        return [
            'enabled' => $this->enabled,
            'excluded_object_ids' => $this->excludedObjectIds,
        ];
    }
}
