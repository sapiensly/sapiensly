<?php

namespace App\Ai\Tools\Builder;

use App\Ai\Tools\Builder\Concerns\EnrichesCatalogEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListAvailableFieldTypesTool implements Tool
{
    use EnrichesCatalogEntries;

    public function name(): string
    {
        return 'list_available_field_types';
    }

    public function description(): string
    {
        return 'List the field types you may use inside object.fields. Each entry includes a prose summary plus `params` (required/optional props + allowed enum values), an `example` skeleton, and the `definition` name to drill into with get_manifest_schema.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $catalog = [
            ['type' => 'string', 'props' => 'default?, min_length?, max_length?, pattern?'],
            ['type' => 'long_text', 'props' => 'default?, max_length?'],
            ['type' => 'number', 'props' => 'default?, min?, max?, precision?, format?'],
            ['type' => 'currency', 'props' => 'currency_code (3-letter ISO, required), default?, min?, max?'],
            ['type' => 'boolean', 'props' => 'default?'],
            ['type' => 'color', 'props' => 'default? (#RRGGBB). Stored as a hex string; the form shows a colour picker + hex input, and tables render a swatch. Good for category/tag/label colours.'],
            ['type' => 'date', 'props' => 'default? (ISO date)'],
            ['type' => 'datetime', 'props' => 'default? (ISO datetime)'],
            ['type' => 'single_select', 'props' => 'options (required, array of {id, value, label, color?}), default?'],
            ['type' => 'multi_select', 'props' => 'options (required, array of {id, value, label, color?}), default?'],
            ['type' => 'relation', 'props' => 'target_object_id (required), cardinality (one_to_one|many_to_one|one_to_many|many_to_many, required), on_delete?, inverse_field_id?'],
            ['type' => 'formula', 'props' => 'expression (string with {{slug}} refs + whitelisted fns: upper, lower, length, coalesce, now(), today()), return_type (string|number|boolean|date|datetime), currency_code? — readonly:true required'],
            ['type' => 'lookup', 'props' => 'via_relation_field_id (must point to a relation field on THIS object), target_field_id (a field on the related object) — readonly:true required. Pulls a value from a related record.'],
            ['type' => 'rollup', 'props' => 'via_relation_field_id (a one_to_many relation on THIS object with inverse_field_id set), aggregator (count|count_distinct|sum|avg|min|max), target_field_id (required for sum/avg/min/max — must be numeric for sum/avg), filter? — readonly:true required. Aggregates the child records.'],
            ['type' => 'rating', 'props' => 'max (2-10, default 5), default?, icon (star|heart|thumb, default star). Stored as integer 0..max. Aggregates as numeric (avg rating, count of 5-stars). Good for feedback, reviews, NPS.'],
            ['type' => 'slider', 'props' => 'min (default 0), max (default 100), step (>0, default 1), default?, format (plain|percentage|currency, default plain), currency_code? (required when format=currency). Stored as number. Aggregates as numeric. Good for ranges, %, budgets.'],
            ['type' => 'date_range', 'props' => 'include_time? (default false), default?{from,to}. Stored as object {from: ISO date|datetime, to: ISO date|datetime}. Good for event windows, vacation periods, report ranges. Filters on date_range are limited to is_null/is_not_null in MVP — for time-window filters use two separate date fields.'],
            ['type' => 'file', 'props' => 'max_size_mb (1-100, default 10), mime_types? (array of MIME patterns like ["image/*", "application/pdf"]; omit to allow anything). Stored as object {file_id, original_name, mime, size_bytes, url}. Uploads go through POST /r/{slug}/uploads, served via GET /r/{slug}/files/{file_id} with tenant auth. Good for contracts, photos, ID scans, attachments. Filters on file fields: only is_null / is_not_null in MVP.'],
            ['type' => 'rich_text', 'props' => 'default? (HTML string), max_length? (over PLAIN text, tags excluded). Stored as sanitised HTML. Editor toolbar: bold/italic/underline, H2/H3, bullet+numbered lists, links. Backend re-sanitises on save with an allowlist (p/br/strong/em/u/b/i/h2/h3/ul/ol/li/a) so XSS in submitted HTML is neutralised. Good for product descriptions, notes, articles, formatted comments. Prefer over long_text when the user benefits from inline formatting.'],
        ];

        return json_encode([
            'field_types' => $this->withSchema('field', $catalog),
            'common_props' => 'All fields must have: id (prefix `fld_` then 8-60 chars of [a-z0-9_] — a lowercased ULID works but is not required), slug (^[a-z][a-z0-9_]*$), name, type. Optional: description, required, unique, indexed, readonly, hidden, help_text.',
            'system_fields' => [
                'note' => 'Every object also has TWO virtual datetime fields you can reference without declaring them. Always prefer these over inventing a manual datetime field for "when was the record created/updated".',
                'available' => [
                    ['id' => 'sys_created_at', 'type' => 'datetime', 'description' => 'When the record was first inserted. Backfilled automatically — works on existing records.'],
                    ['id' => 'sys_updated_at', 'type' => 'datetime', 'description' => 'Last time the record was modified.'],
                ],
                'usage' => 'Reference them as any other field_id in table.columns[].field_id, table.data_source.sort[].field_id, filter conditions, sparkline.x_field_id / sparkline.data_source.filter, heatmap.date_field_id, calendar.date_field_id, timeline.date_field_id, etc. They are READ-ONLY — do NOT use them in form blocks.',
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
