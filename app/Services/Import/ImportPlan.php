<?php

namespace App\Services\Import;

/**
 * What an import WOULD do, before it does any of it.
 *
 * An import is destructive in the way that matters least visibly: it succeeds.
 * Once five thousand rows land under the wrong types, undoing them is worse
 * than never having imported. So the plan is a first-class object a human (or a
 * model) reviews — which object, which column becomes which field, how an
 * ambiguous date was read, what gets skipped — and only then commits.
 */
final class ImportPlan
{
    public const MODE_CREATE = 'create_object';

    public const MODE_EXISTING = 'existing_object';

    /**
     * @param  string  $mode  self::MODE_CREATE | self::MODE_EXISTING
     * @param  array<string, mixed>  $object  the object definition to create, or the target's summary
     * @param  list<ColumnMapping>  $mappings
     * @param  string|null  $upsertKey  field slug that identifies an existing record
     * @param  list<string>  $warnings
     * @param  list<array<string, string|null>>  $sample
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $object,
        public readonly array $mappings,
        public readonly int $totalRows,
        public readonly bool $truncated,
        public readonly ?string $upsertKey = null,
        public readonly array $warnings = [],
        public readonly array $sample = [],
    ) {}

    /**
     * Only the columns that will actually be written.
     *
     * @return list<ColumnMapping>
     */
    public function mapped(): array
    {
        return array_values(array_filter($this->mappings, fn (ColumnMapping $m): bool => $m->fieldSlug !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'object' => $this->object,
            'mappings' => array_map(fn (ColumnMapping $m): array => $m->toArray(), $this->mappings),
            'total_rows' => $this->totalRows,
            'truncated' => $this->truncated,
            'upsert_key' => $this->upsertKey,
            'warnings' => $this->warnings,
            'sample' => $this->sample,
        ];
    }
}
