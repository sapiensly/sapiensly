<?php

namespace App\Services\Connected;

use App\Models\Integration;
use App\Services\Integrations\IntegrationCaller;
use Illuminate\Support\Arr;

/**
 * Runtime write path for connected objects (builder power #2). Given a manifest
 * object whose `source` is `connected`, it creates/updates a record live in the
 * external system through the integration, mapping the manifest field values
 * back to the external request body via `field_map` (the reverse of
 * ConnectedObjectReader::mapRow). Passthrough: it stores NOTHING in our
 * database. The logged-in user is the actor (UI write) — this service is the
 * direct-write half of §4; agent writes go through the propose-don't-mutate gate
 * elsewhere and never reach here.
 *
 * An object that omits the create/update operation is read-only — a write to it
 * degrades to an error result rather than throwing, so the controller surfaces
 * it inline (never a false success).
 */
class ConnectedObjectWriter
{
    public function __construct(private readonly IntegrationCaller $caller) {}

    /**
     * @param  array<string, mixed>  $object  a manifest object_definition with source.type === 'connected'
     * @param  array<string, mixed>  $values  raw values keyed by field slug (matching internal writes)
     * @return array{ok: bool, id?: mixed, error?: string}
     */
    public function create(array $object, Integration $integration, array $values): array
    {
        return $this->write($object, $integration, 'create', null, $values);
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $values
     * @return array{ok: bool, id?: mixed, error?: string}
     */
    public function update(array $object, Integration $integration, string $externalId, array $values): array
    {
        return $this->write($object, $integration, 'update', $externalId, $values);
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $values
     * @return array{ok: bool, id?: mixed, error?: string}
     */
    private function write(array $object, Integration $integration, string $opName, ?string $externalId, array $values): array
    {
        $source = $object['source'] ?? [];
        $op = $source['operations'][$opName] ?? null;

        if (! is_array($op) || empty($op['path'])) {
            return ['ok' => false, 'error' => "This connected object is read-only — no {$opName} operation is configured."];
        }

        $path = (string) $op['path'];
        if ($externalId !== null) {
            $path = str_replace('{id}', rawurlencode($externalId), $path);
        }

        $body = $this->mapPayload($object, $source, $values);

        try {
            $response = $this->caller->send(
                $integration,
                (string) ($op['method'] ?? ($opName === 'create' ? 'POST' : 'PATCH')),
                $path,
                ['json' => $body],
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => "External system returned HTTP {$response->status()}."];
        }

        $json = $response->json() ?? [];
        $id = ! empty($source['id_path']) ? Arr::get($json, $source['id_path']) : null;

        return ['ok' => true, 'id' => $id ?? $externalId];
    }

    /**
     * Reverse of ConnectedObjectReader::mapRow: turn manifest field values
     * (keyed by slug) into a nested external request body using each field's
     * `external_path`. Only sends fields the caller actually provided (partial
     * update) and skips readonly-mapped fields and fields with no mapping.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function mapPayload(array $object, array $source, array $values): array
    {
        $slugById = collect($object['fields'] ?? [])->pluck('slug', 'id')->all();

        $body = [];
        foreach (($source['field_map'] ?? []) as $entry) {
            if (! is_array($entry) || empty($entry['field_id']) || ! isset($entry['external_path'])) {
                continue;
            }
            if (! empty($entry['readonly'])) {
                continue;
            }

            $slug = $slugById[$entry['field_id']] ?? null;
            if ($slug === null || ! array_key_exists($slug, $values)) {
                continue;
            }

            Arr::set($body, (string) $entry['external_path'], $values[$slug]);
        }

        return $body;
    }
}
