<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use Throwable;

/**
 * Fills an app with believable sample records so a freshly-built app isn't
 * empty on first open. Strictly opt-in — only ever run after the user agrees.
 *
 * Values are generated per field type; relation (many_to_one) fields are linked
 * to records of the parent object, which is why objects are seeded
 * parent-before-child. many_to_many fields can't be filled inline — a link needs
 * ids on BOTH sides — so they're wired in a second pass once every object has
 * records (see linkManyToMany). Each row goes through RecordWriteService so the
 * same validation + tenant scoping as the real form path applies; a row that
 * fails validation is skipped rather than aborting the batch.
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

        // Second pass: wire many_to_many links now that every object has ids.
        $this->linkManyToMany($app, $manifest, $objects, $idsByObject, $user);

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
            'number' => $this->numericInRange($field, defaultMin: 1, defaultMax: 100, decimals: 0),
            'currency' => $this->numericInRange($field, defaultMin: 50, defaultMax: 500, decimals: 2),
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
     * Generate a numeric sample that honours the field's declared `min`/`max`,
     * falling back to plausible defaults when they're unset. This keeps seeded
     * values in a believable range (e.g. a price stays modest instead of being
     * a six-figure random number) and respects an author-set ceiling like a
     * line-item quantity max.
     *
     * @param  array<string, mixed>  $field
     */
    private function numericInRange(array $field, float $defaultMin, float $defaultMax, int $decimals): int|float
    {
        $min = isset($field['min']) ? (float) $field['min'] : $defaultMin;
        $max = isset($field['max']) ? (float) $field['max'] : $defaultMax;
        if ($max < $min) {
            $max = $min;
        }

        if ($decimals <= 0) {
            return random_int((int) ceil($min), (int) max(ceil($min), floor($max)));
        }

        $scale = 10 ** $decimals;

        return round(random_int((int) round($min * $scale), (int) max(round($min * $scale), round($max * $scale))) / $scale, $decimals);
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
     * Second seeding pass: populate many_to_many relation fields. This can't run
     * inline with the row (like a many_to_one FK) because a link needs real ids on
     * BOTH sides, and the target may not be seeded yet. Each source record gets a
     * small random subset of the target's records; when the pair is symmetric (the
     * field carries an inverse_field_id, as buildManyToMany produces), the same
     * link is mirrored onto the inverse side so both pickers stay consistent.
     * Each record is updated once, through RecordWriteService (partial update),
     * so the same validation + trigger path as a real edit applies.
     *
     * @param  list<array<string, mixed>>  $objects
     * @param  array<string, list<string>>  $idsByObject
     */
    private function linkManyToMany(App $app, array $manifest, array $objects, array $idsByObject, ?User $user): void
    {
        $slugByFieldId = $this->indexFieldSlugs($objects);

        /** @var array<string, array<string, list<string>>> $assignments  recordId => [fieldSlug => targetIds] */
        $assignments = [];
        $handledInverse = [];

        foreach ($objects as $object) {
            if (isset($object['source'])) {
                continue;
            }
            $sourceIds = $idsByObject[$object['id']] ?? [];
            if ($sourceIds === []) {
                continue;
            }

            foreach ($object['fields'] ?? [] as $field) {
                if (($field['type'] ?? null) !== 'relation' || ($field['cardinality'] ?? null) !== 'many_to_many') {
                    continue;
                }
                $fieldId = (string) ($field['id'] ?? '');
                if (isset($handledInverse[$fieldId])) {
                    continue; // already filled as the inverse of an earlier field
                }
                $targetIds = $idsByObject[$field['target_object_id'] ?? ''] ?? [];
                if ($targetIds === []) {
                    continue;
                }

                $slug = (string) $field['slug'];
                $inverseId = (string) ($field['inverse_field_id'] ?? '');
                $inverseSlug = $inverseId !== '' ? ($slugByFieldId[$inverseId] ?? null) : null;
                $selfRef = ($field['target_object_id'] ?? null) === $object['id'];

                foreach ($sourceIds as $sourceId) {
                    $pool = $selfRef ? array_values(array_diff($targetIds, [$sourceId])) : $targetIds;
                    foreach ($this->pickSubset($pool) as $targetId) {
                        $assignments[$sourceId][$slug][] = $targetId;
                        if ($inverseSlug !== null) {
                            $assignments[$targetId][$inverseSlug][] = $sourceId;
                        }
                    }
                }

                if ($inverseId !== '') {
                    $handledInverse[$inverseId] = true;
                }
            }
        }

        foreach ($assignments as $recordId => $fields) {
            $values = [];
            foreach ($fields as $fieldSlug => $ids) {
                $values[$fieldSlug] = array_values(array_unique($ids));
            }
            try {
                $record = Record::query()->where('app_id', $app->id)->find($recordId);
                if ($record !== null) {
                    $this->writer->update($app, $manifest, $record, $values, $user);
                }
            } catch (Throwable) {
                // Skip a link the manifest's own rules reject; keep going.
            }
        }
    }

    /**
     * Pick a small random subset (1..3, capped at pool size) of target ids for one
     * many_to_many link, so seeded pickers look believable without linking everything.
     *
     * @param  list<string>  $pool
     * @return list<string>
     */
    private function pickSubset(array $pool): array
    {
        if ($pool === []) {
            return [];
        }
        $count = random_int(1, min(3, count($pool)));
        $keys = (array) array_rand($pool, $count);

        return array_values(array_map(static fn ($k): string => $pool[$k], $keys));
    }

    /**
     * Map every field id in the manifest to its slug, so a field's inverse_field_id
     * can be resolved to the slug to write on the other side of a symmetric pair.
     *
     * @param  list<array<string, mixed>>  $objects
     * @return array<string, string>
     */
    private function indexFieldSlugs(array $objects): array
    {
        $map = [];
        foreach ($objects as $object) {
            foreach ($object['fields'] ?? [] as $field) {
                if (isset($field['id'], $field['slug'])) {
                    $map[(string) $field['id']] = (string) $field['slug'];
                }
            }
        }

        return $map;
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
