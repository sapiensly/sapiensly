<?php

namespace App\Services\Tracking;

use App\Models\App;
use App\Models\LocationPing;
use App\Models\Record;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\Workflows\WorkflowTriggerDispatcher;
use App\Support\Tracking\Geofence;
use App\Support\Tracking\TrackingPolicy;
use Illuminate\Support\Carbon;

/**
 * Starting a trail, recording it, and noticing an arrival.
 *
 * The arrival is the part worth having. A trail is a picture somebody looks at
 * afterwards; `location.arrived` is what closes the loop — the visit stamps
 * itself, the customer gets a message, the dispatcher stops asking. It fires
 * through the ordinary workflow dispatcher with a `record` payload, so filters
 * and every existing step work on it unchanged.
 */
class TrackingService
{
    public function __construct(
        private readonly WorkflowTriggerDispatcher $dispatcher,
    ) {}

    /**
     * Begin, with a fence when there is something to draw one around.
     *
     * The target is resolved NOW and copied onto the session. Reading it live
     * would mean that moving a work order's pin mid-visit retroactively changes
     * where somebody was judged to have arrived, which is a fact about the past
     * that nobody should be able to edit.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function start(
        App $app,
        array $manifest,
        User $user,
        TrackingPolicy $policy,
        ?string $recordId = null,
    ): TrackingSession {
        // One live session per person per app. Starting again ends the last
        // one rather than running two: two trails for one person is a question
        // nobody can answer ("which of these is where they were?").
        $this->endLiveSessions($app, $user);

        $target = $recordId === null ? null : $this->targetOf($app, $manifest, $recordId);

        return TrackingSession::create([
            'app_id' => $app->id,
            'user_id' => $user->id,
            'object_id' => $target['object_id'] ?? null,
            'record_id' => $target === null ? null : $recordId,
            'target_lat' => $target['lat'] ?? null,
            'target_lng' => $target['lng'] ?? null,
            'radius_m' => $target === null ? null : $policy->radiusMeters,
            'started_at' => now(),
        ]);
    }

    /**
     * Store what a phone reported, and decide whether anything happened.
     *
     * @param  list<array{lat: float, lng: float, accuracy?: float|null, at?: string|null, gap?: bool}>  $pings
     * @param  array<string, mixed>  $manifest
     * @return array{stored: int, inside: bool|null, events: list<string>}
     */
    public function record(
        App $app,
        array $manifest,
        TrackingSession $session,
        array $pings,
        ?User $user = null,
    ): array {
        $stored = 0;
        $events = [];

        foreach ($pings as $ping) {
            $lat = (float) ($ping['lat'] ?? 0);
            $lng = (float) ($ping['lng'] ?? 0);

            // Zero, zero is the Atlantic. It is also what a half-filled payload
            // looks like, and one of those is far more likely than the other.
            if (! $this->plausible($lat, $lng)) {
                continue;
            }

            $accuracy = isset($ping['accuracy']) && is_numeric($ping['accuracy'])
                ? (float) $ping['accuracy']
                : null;

            LocationPing::create([
                'app_id' => $app->id,
                'user_id' => $session->user_id,
                'session_id' => $session->id,
                'lat' => $lat,
                'lng' => $lng,
                'accuracy_m' => $accuracy === null ? null : (int) round($accuracy),
                'gap' => (bool) ($ping['gap'] ?? false),
                'recorded_at' => $this->timeOf($ping),
            ]);

            $stored++;

            $event = $this->judgeArrival($app, $manifest, $session, $lat, $lng, $accuracy, $user);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        $session->update(['last_ping_at' => now()]);

        return ['stored' => $stored, 'inside' => $session->inside, 'events' => $events];
    }

    public function stop(TrackingSession $session): void
    {
        if ($session->ended_at === null) {
            $session->update(['ended_at' => now()]);
        }
    }

    public function liveSession(App $app, User $user): ?TrackingSession
    {
        return TrackingSession::query()
            ->where('app_id', $app->id)
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();
    }

    /**
     * Did this reading cross the fence? Returns the event fired, or null.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function judgeArrival(
        App $app,
        array $manifest,
        TrackingSession $session,
        float $lat,
        float $lng,
        ?float $accuracy,
        ?User $user,
    ): ?string {
        if ($session->target_lat === null || $session->target_lng === null) {
            return null;
        }

        $radius = (float) ($session->radius_m ?? 150);

        // A reading too vague to decide with leaves the state ALONE, including
        // when there is no state yet. Letting it fall through would write
        // "outside" for a phone that is simply unsure, and the next good fix
        // would then read as an arrival that never happened.
        if (! Geofence::canDecide($accuracy, $radius)) {
            return null;
        }

        $distance = Geofence::distanceMeters($lat, $lng, $session->target_lat, $session->target_lng);
        $was = $session->inside;
        $now = Geofence::nextState($was ?? false, $distance, $radius, $accuracy);

        if ($was === $now) {
            return null;
        }

        $session->update(['inside' => $now]);

        // The FIRST decided reading is not a crossing. Somebody who opens the
        // app already standing at the site has not arrived while it watched —
        // and firing "llegó" for the state the session started in is how a
        // workflow sends a customer "vamos en camino" from their own doorstep.
        if ($was === null) {
            return null;
        }

        $event = $now ? 'location.arrived' : 'location.departed';

        $record = Record::query()
            ->where('app_id', $app->id)
            ->where('id', $session->record_id)
            ->first();

        if ($record === null) {
            return null;
        }

        $this->dispatcher->dispatch($app, $manifest, $event, [
            'record' => [
                'id' => $record->id,
                'object_definition_id' => $record->object_definition_id,
                'data' => $record->data,
            ],
            'location' => [
                'lat' => $lat,
                'lng' => $lng,
                'distance_m' => (int) round($distance),
            ],
        ], $user);

        return $event;
    }

    /**
     * The place a record names, if it names one.
     *
     * Reads the FIRST geo field on the record's object rather than making the
     * author nominate one: an object with two places on it is rare, and an
     * author who has to configure a field id to use a geofence mostly does not
     * use the geofence.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{object_id: string, lat: float, lng: float}|null
     */
    private function targetOf(App $app, array $manifest, string $recordId): ?array
    {
        $record = Record::query()
            ->where('app_id', $app->id)
            ->where('id', $recordId)
            ->first();

        if ($record === null) {
            return null;
        }

        foreach ($manifest['objects'] ?? [] as $object) {
            if (($object['id'] ?? null) !== $record->object_definition_id) {
                continue;
            }

            foreach ($object['fields'] ?? [] as $field) {
                if (($field['type'] ?? null) !== 'geo') {
                    continue;
                }

                $point = $record->data[$field['slug'] ?? ''] ?? null;
                if (! is_array($point) || ! isset($point['lat'], $point['lng'])) {
                    continue;
                }

                return [
                    'object_id' => (string) $object['id'],
                    'lat' => (float) $point['lat'],
                    'lng' => (float) $point['lng'],
                ];
            }
        }

        return null;
    }

    private function endLiveSessions(App $app, User $user): void
    {
        TrackingSession::query()
            ->where('app_id', $app->id)
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);
    }

    private function plausible(float $lat, float $lng): bool
    {
        return $lat >= -90 && $lat <= 90
            && $lng >= -180 && $lng <= 180
            && ! ($lat === 0.0 && $lng === 0.0);
    }

    /** @param array<string, mixed> $ping */
    private function timeOf(array $ping): Carbon
    {
        $at = $ping['at'] ?? null;

        if (! is_string($at) || $at === '') {
            return now();
        }

        try {
            $parsed = Carbon::parse($at);
        } catch (\Throwable) {
            return now();
        }

        // A phone's clock is whatever its owner set it to. A ping claiming to
        // be from next week would sort ahead of everything for ever.
        return $parsed->isFuture() ? now() : $parsed;
    }
}
