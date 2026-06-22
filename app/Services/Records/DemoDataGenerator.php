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
                $values = $this->valuesFor($object, $i, $idsByObject);
                try {
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
            'long_text', 'rich_text' => fake()->paragraph(),
            'number' => fake()->numberBetween((int) ($field['min'] ?? 1), (int) ($field['max'] ?? 1000)),
            'currency' => fake()->randomFloat(2, 10, 10000),
            'boolean' => fake()->boolean(),
            'date' => fake()->dateTimeBetween('-30 days', '+15 days')->format('Y-m-d'),
            'datetime' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d H:i:s'),
            'single_select' => $this->randomOption($field),
            'multi_select' => array_values(array_unique([$this->randomOption($field)])),
            'rating' => fake()->numberBetween(1, (int) ($field['max'] ?? 5)),
            'slider' => fake()->numberBetween((int) ($field['min'] ?? 0), (int) ($field['max'] ?? 100)),
            'relation' => $this->randomParentId($field, $idsByObject),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function stringValue(string $slug, int $index, array $field): string
    {
        $value = match (true) {
            str_contains($slug, 'email') => fake()->safeEmail(),
            str_contains($slug, 'phone') => fake()->phoneNumber(),
            str_contains($slug, 'name') || str_contains($slug, 'title') => fake()->sentence(3),
            str_contains($slug, 'url') || str_contains($slug, 'link') => fake()->url(),
            default => fake()->words(2, true),
        };
        $value = rtrim($value, '.');

        $max = $field['max_length'] ?? null;

        return $max !== null ? mb_substr($value, 0, (int) $max) : $value;
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
