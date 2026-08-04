<?php

namespace App\Services\Records;

use App\Facades\TenantCache;
use App\Services\Ai\AiCapabilities;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

/**
 * A question in somebody's own words, turned into a filter the app already
 * understands.
 *
 * "órdenes de más de 5.000 este mes" becomes a `filter_expression` — the same
 * grammar an author writes by hand and the same one the query layer has always
 * executed. Nothing new reaches the database: the model chooses among operators
 * that already existed, on fields that already existed.
 *
 * WHAT COMES BACK IS NOT TRUSTED. Every node is checked against the object's own
 * fields and the operator list before it is used, and anything unrecognised
 * throws the whole expression away rather than being dropped quietly — half a
 * filter is worse than none, because the rows that come back look like an
 * answer to the question that was asked.
 *
 * It can only ever NARROW. The compiled expression is ANDed with whatever the
 * block already had, and the role's row_filter is applied separately downstream,
 * so no phrase can widen what somebody is allowed to see.
 */
class NaturalFilterCompiler
{
    /** Operators a phrase may reach. Deliberately a subset of the grammar. */
    private const OPERATORS = [
        'and', 'or', 'not',
        'eq', 'neq', 'gt', 'gte', 'lt', 'lte',
        'in', 'not_in', 'contains', 'starts_with', 'ends_with',
        'is_null', 'is_not_null', 'between',
    ];

    /** How deep a generated expression may nest. */
    private const MAX_DEPTH = 4;

    /** A translation is stable, so it is worth remembering rather than re-billing. */
    private const CACHE_MINUTES = 60 * 24;

    public function __construct(private readonly AiCapabilities $capabilities) {}

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null a filter_expression, or null when the
     *                                   phrase could not be turned into one
     */
    public function compile(string $phrase, array $object, string $locale = 'en'): ?array
    {
        $phrase = trim($phrase);
        if ($phrase === '' || mb_strlen($phrase) > 300) {
            return null;
        }

        $key = 'nlfilter:'.($object['id'] ?? '').':'.md5($phrase.'|'.$locale);

        try {
            $cached = TenantCache::get($key);
            if (is_array($cached)) {
                return $cached['filter'] ?? null;
            }
        } catch (Throwable) {
            // No tenant scope (a console context): translate without caching
            // rather than refusing to answer.
        }

        $filter = $this->translate($phrase, $object, $locale);

        try {
            TenantCache::put($key, ['filter' => $filter], now()->addMinutes(self::CACHE_MINUTES));
        } catch (Throwable) {
            // As above.
        }

        return $filter;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null
     */
    private function translate(string $phrase, array $object, string $locale): ?array
    {
        $handler = $this->capabilities->resolve('chat');
        if ($handler === null) {
            return null;
        }

        $fields = $this->filterableFields($object);
        if ($fields === []) {
            return null;
        }

        try {
            $reply = '';
            $stream = (new AnonymousAgent($this->system($fields, $locale), [], []))->stream(
                $phrase,
                provider: $handler['provider'],
                model: $handler['model'],
            );

            foreach ($stream as $event) {
                if ($event instanceof TextDelta) {
                    $reply .= $event->delta;
                }
            }

            $start = strpos($reply, '{');
            $end = strrpos($reply, '}');
            if ($start === false || $end === false) {
                return null;
            }

            $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);

            return is_array($decoded) && $this->isValid($decoded, $fields)
                ? $decoded
                : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Fields a phrase may mention. Relations and files are excluded: filtering
     * them takes a record id or a shape no sentence contains.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, array<string, mixed>> keyed by field id
     */
    private function filterableFields(array $object): array
    {
        $out = [];

        foreach ($object['fields'] ?? [] as $field) {
            if (in_array($field['type'] ?? '', ['relation', 'file', 'geo', 'date_range', 'multi_select'], true)) {
                continue;
            }

            $out[$field['id']] = $field;
        }

        // The two the query layer always has, and the two most questions are
        // really about: "this month", "added today".
        $out['sys_created_at'] = ['id' => 'sys_created_at', 'slug' => 'sys_created_at', 'name' => 'Created at', 'type' => 'datetime'];
        $out['sys_updated_at'] = ['id' => 'sys_updated_at', 'slug' => 'sys_updated_at', 'name' => 'Updated at', 'type' => 'datetime'];

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function system(array $fields, string $locale): string
    {
        $lines = [];

        foreach ($fields as $id => $field) {
            $line = "- {$id} ({$field['type']}): ".($field['name'] ?? $field['slug']);

            if (($field['type'] ?? '') === 'single_select') {
                $values = array_map(fn (array $o): string => (string) ($o['value'] ?? ''), $field['options'] ?? []);
                $line .= '. Values: '.implode(', ', array_filter($values));
            }

            $lines[] = $line;
        }

        $today = now()->toDateString();
        $fieldList = implode("\n", $lines);
        $operators = implode(', ', self::OPERATORS);

        return <<<PROMPT
            Turn the user's question into a JSON filter over these fields.
            Today is {$today}. The question is written in {$locale}.

            Fields (use the id on the left as field_id):
            {$fieldList}

            Grammar:
            {"op":"and","conditions":[...]} — also "or"
            {"op":"not","condition":{...}}
            {"op":"<comparison>","field_id":"...","value":...}
            Comparisons: {$operators}
            `between` takes "value":[low,high]; `in`/`not_in` take an array.
            Dates are "YYYY-MM-DD" strings; resolve "this month", "last week"
            and the like against today's date into a `between`.

            Answer with the JSON object only — no prose, no code fence. If the
            question cannot be expressed over these fields, answer exactly {}.
            PROMPT;
    }

    /**
     * Everything the model sent, checked against what the object really has.
     *
     * An unknown field id or an operator outside the list invalidates the WHOLE
     * expression rather than being pruned: a filter missing one of its
     * conditions still returns rows, and those rows look like an answer to the
     * question somebody asked.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function isValid(array $node, array $fields, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        $op = $node['op'] ?? null;
        if (! is_string($op) || ! in_array($op, self::OPERATORS, true)) {
            return false;
        }

        if ($op === 'and' || $op === 'or') {
            $conditions = $node['conditions'] ?? null;
            if (! is_array($conditions) || $conditions === []) {
                return false;
            }

            foreach ($conditions as $condition) {
                if (! is_array($condition) || ! $this->isValid($condition, $fields, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        if ($op === 'not') {
            $condition = $node['condition'] ?? null;

            return is_array($condition) && $this->isValid($condition, $fields, $depth + 1);
        }

        // A comparison. The field has to be one this object actually has —
        // this is the check that keeps a phrase from reaching a column the
        // reader was never shown.
        $fieldId = $node['field_id'] ?? null;
        if (! is_string($fieldId) || ! isset($fields[$fieldId])) {
            Log::debug('Natural filter named an unknown field', ['field_id' => $fieldId]);

            return false;
        }

        if (in_array($op, ['is_null', 'is_not_null'], true)) {
            return true;
        }

        if (! array_key_exists('value', $node)) {
            return false;
        }

        if (in_array($op, ['in', 'not_in', 'between'], true)) {
            return is_array($node['value']) && $node['value'] !== [];
        }

        return is_scalar($node['value']);
    }
}
