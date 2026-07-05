<?php

namespace App\Services\Express;

use App\Services\Records\InMemoryRowFilter;

/**
 * Real numbers for the insight gate: aggregates computed over the rows the
 * acquisition phase ALREADY fetched, so the model narrates facts instead of
 * imagining them — the prompt carries "top category: Envíos (34%)", not a
 * request to guess. Everything here is derived; nothing asks a model.
 */
class ComputedFactsBuilder
{
    /**
     * @param  array<string, mixed>  $object  manifest object node
     * @param  list<array<string, mixed>>  $rows  raw external rows (as sampled)
     * @return array<string, mixed>
     */
    public function build(array $object, array $rows): array
    {
        $facts = [
            'object' => $object['name'] ?? $object['slug'] ?? '',
            'row_count' => count($rows),
        ];

        $fields = array_values(array_filter($object['fields'] ?? [], 'is_array'));
        $pathByFieldId = collect($object['source']['field_map'] ?? [])
            ->pluck('external_path', 'field_id')->all();
        $valueOf = function (array $row, array $field) use ($pathByFieldId): mixed {
            $path = $pathByFieldId[$field['id']] ?? ($field['slug'] ?? null);

            return $path !== null ? data_get($row, $path) : null;
        };

        foreach ($fields as $field) {
            $type = $field['type'] ?? 'string';
            $name = (string) ($field['name'] ?? $field['slug']);
            $values = array_values(array_filter(
                array_map(fn (array $r) => $valueOf($r, $field), $rows),
                fn ($v) => $v !== null && $v !== '',
            ));
            if ($values === []) {
                continue;
            }

            if (in_array($type, ['number', 'currency'], true)) {
                $numeric = array_values(array_filter($values, 'is_numeric'));
                if ($numeric !== []) {
                    sort($numeric);
                    $facts['numeric'][$name] = [
                        'sum' => round(array_sum($numeric), 2),
                        'avg' => round(array_sum($numeric) / count($numeric), 2),
                        'median' => $numeric[intdiv(count($numeric), 2)],
                        'max' => end($numeric),
                    ];
                }

                continue;
            }

            if ($type === 'boolean') {
                $true = count(array_filter($values, fn ($v) => $v === true || $v === 1 || $v === '1'));
                $facts['rates'][$name] = [
                    'true_count' => $true,
                    'rate_pct' => round($true * 100 / count($values), 1),
                ];

                continue;
            }

            if ($type === 'string') {
                $counts = array_count_values(array_map(fn ($v) => (string) $v, $values));
                arsort($counts);
                $distinct = count($counts);
                if ($distinct >= 2 && $distinct <= 25) {
                    $top = array_key_first($counts);
                    $facts['top_values'][$name] = [
                        'top' => $top,
                        'count' => $counts[$top],
                        'share_pct' => round($counts[$top] * 100 / count($values), 1),
                        'distinct' => $distinct,
                    ];
                }

                continue;
            }

            if (in_array($type, ['date', 'datetime'], true)) {
                $timestamps = array_values(array_filter(array_map(
                    fn ($v) => InMemoryRowFilter::timestamp($v),
                    $values,
                )));
                if ($timestamps === []) {
                    continue;
                }
                $weekAgo = now()->utc()->subDays(7)->getTimestamp();
                $twoWeeksAgo = now()->utc()->subDays(14)->getTimestamp();
                $lastWeek = count(array_filter($timestamps, fn (int $t) => $t >= $weekAgo));
                $prevWeek = count(array_filter($timestamps, fn (int $t) => $t >= $twoWeeksAgo && $t < $weekAgo));
                $facts['trend'][$name] = [
                    'last_7d' => $lastWeek,
                    'previous_7d' => $prevWeek,
                    'direction' => $lastWeek <=> $prevWeek,
                    'span_from' => date('Y-m-d', min($timestamps)),
                    'span_to' => date('Y-m-d', max($timestamps)),
                ];
            }
        }

        return $facts;
    }
}
