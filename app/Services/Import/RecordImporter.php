<?php

namespace App\Services\Import;

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Records\RecordValidationException;
use App\Services\Records\RecordWriteService;
use Throwable;

/**
 * Writes the rows.
 *
 * Every row goes through {@see RecordWriteService}, the same path a typed form
 * uses — so imported data obeys the same required flags, option lists, relation
 * resolution and field rules as data a person entered. An importer with its own
 * shortcut into the table is how a database ends up holding rows the app itself
 * would have refused.
 *
 * One row's failure never stops the rest: the file is the user's, the mistakes
 * in it are theirs to see, and the 4,900 good rows should still land.
 */
class RecordImporter
{
    /** Rows per transaction — bounded so one huge import can't hold a long lock. */
    private const CHUNK = 200;

    public function __construct(
        private readonly RecordWriteService $writer,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function import(
        App $app,
        array $manifest,
        ImportPlan $plan,
        SheetData $sheet,
        ?User $user = null,
    ): ImportResult {
        $objectId = (string) $plan->object['id'];
        $mapped = $plan->mapped();

        if ($mapped === []) {
            return new ImportResult(0, 0, 0, [], $sheet->truncated);
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach (array_chunk($sheet->rows, self::CHUNK, true) as $chunk) {
            foreach ($chunk as $index => $row) {
                // +2: the header occupies line 1, and rows are 1-indexed — this
                // is the number the user sees in their spreadsheet.
                $line = $index + 2;

                $values = $this->coerceRow($row, $mapped);
                if ($values === []) {
                    continue;
                }

                try {
                    $existing = $plan->upsertKey !== null
                        ? $this->findByKey($app, $objectId, $plan->upsertKey, $values[$plan->upsertKey] ?? null)
                        : null;

                    if ($existing !== null) {
                        $this->writer->update($app, $manifest, $existing, $values, $user);
                        $updated++;
                    } else {
                        $this->writer->create($app, $manifest, $objectId, $values, $user);
                        $created++;
                    }
                } catch (RecordValidationException $e) {
                    $errors[] = ['row' => $line, 'errors' => $e->errors];
                } catch (Throwable $e) {
                    $errors[] = ['row' => $line, 'errors' => ['_' => [$e->getMessage()]]];
                }
            }
        }

        return new ImportResult($created, $updated, count($errors), $errors, $sheet->truncated);
    }

    /**
     * Convert one row's cells into the shapes RecordWriteService accepts.
     *
     * Blank cells are OMITTED rather than sent as null: on an upsert, sending
     * null would wipe a value the existing record already holds, which is not
     * what "this column was empty in my file" means.
     *
     * @param  array<string, string|null>  $row
     * @param  list<ColumnMapping>  $mapped
     * @return array<string, mixed>
     */
    private function coerceRow(array $row, array $mapped): array
    {
        $values = [];

        foreach ($mapped as $mapping) {
            $raw = $row[$mapping->header] ?? null;
            if ($raw === null) {
                continue;
            }

            $value = $this->coerce($raw, $mapping);
            if ($value !== null) {
                $values[(string) $mapping->fieldSlug] = $value;
            }
        }

        return $values;
    }

    /**
     * A value that will not convert is passed through UNCHANGED on purpose: the
     * writer then rejects it with a message naming the field, and the row is
     * reported with its line number. Dropping it here would import the row
     * silently missing that column — the failure mode where a user discovers
     * three weeks later that a tenth of their prices are blank.
     */
    private function coerce(string $raw, ColumnMapping $mapping): mixed
    {
        return match ($mapping->type) {
            'number', 'currency', 'rating', 'slider' => ValueCoercion::toNumber($raw) ?? $raw,
            'boolean' => ValueCoercion::toBoolean($raw) ?? $raw,
            'date' => ValueCoercion::toDate($raw, $mapping->profile->dayFirst) ?? $raw,
            'datetime' => ValueCoercion::toDate($raw, $mapping->profile->dayFirst, withTime: true) ?? $raw,
            'multi_select' => ValueCoercion::splitList($raw, $mapping->profile->listSeparator ?? ',') ?? [$raw],
            default => $raw,
        };
    }

    /**
     * Find the record this row updates, by the plan's matching column. The
     * lookup is a JSONB equality on the object's own rows, and RLS keeps it
     * inside the tenant — a key value from a file can never reach another org.
     */
    private function findByKey(App $app, string $objectId, string $slug, mixed $value): ?Record
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $objectId)
            ->whereRaw('data->>? = ?', [$slug, (string) $value])
            ->first();
    }
}
