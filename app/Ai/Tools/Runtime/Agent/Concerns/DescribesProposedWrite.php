<?php

namespace App\Ai\Tools\Runtime\Agent\Concerns;

/**
 * Shared manifest lookups + human-readable preview building for the runtime
 * agent's propose_* write tools (builder power #3). The preview is what the user
 * sees on the action card before approving — legibility is the gate's whole point.
 */
trait DescribesProposedWrite
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    protected function findObject(array $manifest, string $objectId): ?array
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            if (($object['id'] ?? null) === $objectId) {
                return $object;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    protected function objectName(array $object): string
    {
        return (string) ($object['name'] ?? $object['slug'] ?? 'record');
    }

    /**
     * Render "Field = value, Other = value" using each field's display name
     * (values arrive keyed by slug, matching the internal write path).
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $values
     */
    protected function describeValues(array $object, array $values): string
    {
        $nameBySlug = [];
        foreach ($object['fields'] ?? [] as $field) {
            $nameBySlug[$field['slug'] ?? ''] = $field['name'] ?? $field['slug'] ?? '';
        }

        $parts = [];
        foreach ($values as $slug => $value) {
            $label = $nameBySlug[$slug] ?? $slug;
            $parts[] = $label.' = '.$this->scalarize($value);
        }

        return implode(', ', $parts);
    }

    protected function scalarize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[…]';
        }

        return (string) $value;
    }
}
