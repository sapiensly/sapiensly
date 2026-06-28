<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\User;
use Throwable;

/**
 * Fills an app with believable sample records so a freshly-built app isn't
 * empty on first open. Strictly opt-in — only ever run after the user agrees.
 *
 * Values are generated per field type; relation (many_to_one) fields are linked
 * to records of the parent object, which is why objects are seeded
 * parent-before-child. Each row goes through RecordWriteService so the same
 * validation + tenant scoping as the real form path applies; a row that fails
 * validation is skipped rather than aborting the batch.
 */
class DemoDataGenerator
{
    /** Read-only / computed field types that are never written. */
    private const SKIP_TYPES = ['rollup', 'lookup', 'formula', 'one_to_many', 'date_range', 'file'];

    public function __construct(private readonly RecordWriteService $writer) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>|null  $onlySlugs  limit to these object slugs (null = all)
     * @return array<string, int> created count keyed by object slug
     */
    public function generate(App $app, array $manifest, int $perObject, ?array $onlySlugs = null, ?User $user = null): array
    {
        $objects = $this->orderByDependency($manifest['objects'] ?? []);

        $idsByObject = [];
        $summary = [];

        foreach ($objects as $object) {
            // Connected/external objects are read-only; never seed them.
            if (isset($object['source'])) {
                continue;
            }
            if ($onlySlugs !== null && ! in_array($object['slug'], $onlySlugs, true)) {
                continue;
            }

            $created = [];
            for ($i = 0; $i < $perObject; $i++) {
                try {
                    $values = $this->valuesFor($object, $i, $idsByObject);
                    $record = $this->writer->create($app, $manifest, $object['id'], $values, $user);
                    $created[] = $record->id;
                } catch (Throwable) {
                    // Skip a row the manifest's own rules reject; keep going.
                }
            }

            $idsByObject[$object['id']] = $created;
            $summary[$object['slug']] = count($created);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, list<string>>  $idsByObject  created record ids per object id
     * @return array<string, mixed> values keyed by field slug
     */
    private function valuesFor(array $object, int $index, array $idsByObject): array
    {
        $values = [];
        foreach ($object['fields'] ?? [] as $field) {
            if (($field['readonly'] ?? false) === true || in_array($field['type'] ?? '', self::SKIP_TYPES, true)) {
                continue;
            }
            $value = $this->valueForField($field, $index, $idsByObject);
            if ($value !== null) {
                $values[$field['slug']] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, list<string>>  $idsByObject
     */
    private function valueForField(array $field, int $index, array $idsByObject): mixed
    {
        $slug = (string) ($field['slug'] ?? '');

        return match ($field['type'] ?? 'string') {
            'string' => $this->stringValue($slug, $index, $field),
            'long_text', 'rich_text' => ucfirst($this->words(random_int(8, 16))).'.',
            'number' => random_int((int) ($field['min'] ?? 1), max((int) ($field['min'] ?? 1), (int) ($field['max'] ?? 1000))),
            'currency' => round(random_int(1000, 1_000_000) / 100, 2),
            'boolean' => (bool) random_int(0, 1),
            'date' => $this->dateValue(withTime: false),
            'datetime' => $this->dateValue(withTime: true),
            'single_select' => $this->randomOption($field),
            'multi_select' => ($o = $this->randomOption($field)) === null ? [] : [$o],
            'rating' => random_int(1, max(1, (int) ($field['max'] ?? 5))),
            'slider' => random_int((int) ($field['min'] ?? 0), max((int) ($field['min'] ?? 0), (int) ($field['max'] ?? 100))),
            'relation' => $this->randomParentId($field, $idsByObject),
            'color' => sprintf('#%06x', random_int(0, 0xFFFFFF)),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function stringValue(string $slug, int $index, array $field): string
    {
        $value = match (true) {
            str_contains($slug, 'email') => $this->emailValue(),
            str_contains($slug, 'phone') => $this->phoneValue(),
            str_contains($slug, 'name') || str_contains($slug, 'title') => $this->nameValue(),
            str_contains($slug, 'url') || str_contains($slug, 'link') => $this->urlValue(),
            default => ucfirst($this->words(2)),
        };

        $max = $field['max_length'] ?? null;

        return $max !== null ? mb_substr($value, 0, (int) $max) : $value;
    }

    // --- Built-in value generators (no Faker; runs in production, not dev-only). ---

    private const WORDS = [
        'alfa', 'beta', 'gamma', 'delta', 'orbita', 'nova', 'prisma', 'vertex',
        'pulso', 'aura', 'zenit', 'lumen', 'cobalto', 'iris', 'atlas', 'quantum',
        'flux', 'eco', 'vela', 'duna', 'cresta', 'faro', 'sol', 'cima',
    ];

    private const FIRST_NAMES = [
        'Ana', 'Luis', 'María', 'Carlos', 'Sofía', 'Diego', 'Valeria', 'Jorge',
        'Lucía', 'Pablo', 'Elena', 'Mateo', 'Carmen', 'Andrés',
    ];

    private const LAST_NAMES = [
        'García', 'López', 'Martínez', 'Ramírez', 'Torres', 'Flores', 'Rivera',
        'Cruz', 'Reyes', 'Morales', 'Ortiz', 'Castillo',
    ];

    /**
     * @param  list<string>  $list
     */
    private function pick(array $list): string
    {
        return $list[array_rand($list)];
    }

    private function words(int $count): string
    {
        $out = [];
        for ($i = 0; $i < max(1, $count); $i++) {
            $out[] = self::WORDS[array_rand(self::WORDS)];
        }

        return implode(' ', $out);
    }

    private function nameValue(): string
    {
        return $this->pick(self::FIRST_NAMES).' '.$this->pick(self::LAST_NAMES);
    }

    private function emailValue(): string
    {
        return strtolower($this->pick(self::WORDS)).random_int(1, 999).'@example.com';
    }

    private function phoneValue(): string
    {
        return '+52 55 '.random_int(1000, 9999).' '.random_int(1000, 9999);
    }

    private function urlValue(): string
    {
        return 'https://'.$this->pick(self::WORDS).'.example.com';
    }

    private function dateValue(bool $withTime): string
    {
        $timestamp = time() + random_int(-30, 15) * 86400;

        return $withTime ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d', $timestamp);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function randomOption(array $field): ?string
    {
        $options = $field['options'] ?? [];
        if ($options === []) {
            return null;
        }

        return (string) $options[array_rand($options)]['value'];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, list<string>>  $idsByObject
     */
    private function randomParentId(array $field, array $idsByObject): ?string
    {
        // Only the many_to_one side carries a foreign key to set.
        if (($field['cardinality'] ?? null) !== 'many_to_one') {
            return null;
        }
        $candidates = $idsByObject[$field['target_object_id'] ?? ''] ?? [];

        return $candidates === [] ? null : $candidates[array_rand($candidates)];
    }

    /**
     * Order objects so a parent is seeded before any child that links to it
     * (so many_to_one fields find real ids). Cycles/unresolved deps fall back
     * to original order — those relations just stay null.
     *
     * @param  list<array<string, mixed>>  $objects
     * @return list<array<string, mixed>>
     */
    private function orderByDependency(array $objects): array
    {
        $remaining = $objects;
        $ordered = [];
        $placed = [];

        // At most N passes; whatever can't be resolved is appended as-is.
        for ($pass = 0; $pass < count($objects) && $remaining !== []; $pass++) {
            $progress = false;
            foreach ($remaining as $key => $object) {
                if ($this->dependenciesPlaced($object, $placed)) {
                    $ordered[] = $object;
                    $placed[$object['id']] = true;
                    unset($remaining[$key]);
                    $progress = true;
                }
            }
            if (! $progress) {
                break;
            }
        }

        return [...$ordered, ...array_values($remaining)];
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, bool>  $placed
     */
    private function dependenciesPlaced(array $object, array $placed): bool
    {
        foreach ($object['fields'] ?? [] as $field) {
            if (($field['type'] ?? null) === 'relation'
                && ($field['cardinality'] ?? null) === 'many_to_one'
            ) {
                $target = $field['target_object_id'] ?? null;
                // A self-reference can't be satisfied up front; don't block on it.
                if ($target !== null && $target !== $object['id'] && ! isset($placed[$target])) {
                    return false;
                }
            }
        }

        return true;
    }
}
