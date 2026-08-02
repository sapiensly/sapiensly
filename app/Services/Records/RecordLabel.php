<?php

namespace App\Services\Records;

/**
 * Which field of an object stands in for a whole record.
 *
 * A relation stores an id, and an id is noise to a person: everywhere one is
 * shown — a table column, a card title, a picker's list — something has to say
 * which record it is. That answer is the author's `primary_display_field_id`
 * when they set one, and the first field that reads like a name when they did
 * not, which is the same guess the scaffolder makes when it picks a card title.
 *
 * Here rather than inside one caller because the picker and the table must
 * agree: choosing a vehicle by its plate and then seeing its id in the column
 * would read as two different records.
 */
class RecordLabel
{
    /** Types that can carry a name a person would recognise. */
    private const TEXT_TYPES = ['string', 'long_text', 'rich_text'];

    /**
     * The slug of the field that labels this object's records, or null when it
     * holds nothing text-like to label a row with.
     *
     * @param  array<string, mixed>|null  $object
     */
    public static function displaySlug(?array $object): ?string
    {
        if ($object === null) {
            return null;
        }

        $primary = $object['primary_display_field_id'] ?? null;
        foreach ($object['fields'] ?? [] as $field) {
            if ($primary !== null && ($field['id'] ?? null) === $primary) {
                return $field['slug'] ?? null;
            }
        }

        foreach ($object['fields'] ?? [] as $field) {
            if (in_array($field['type'] ?? null, self::TEXT_TYPES, true)) {
                return $field['slug'] ?? null;
            }
        }

        return null;
    }

    /**
     * What to show for one record of this object.
     *
     * Falls back to the id rather than to an empty string: a picker row with no
     * text is unclickable, and an id at least tells two rows apart.
     *
     * @param  array<string, mixed>|null  $object
     * @param  array<string, mixed>  $data
     */
    public static function of(?array $object, array $data, string $recordId): string
    {
        $slug = self::displaySlug($object);
        $value = $slug === null ? null : ($data[$slug] ?? null);

        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }

        return $recordId;
    }
}
