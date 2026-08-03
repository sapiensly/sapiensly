<?php

namespace App\Services\Records;

use RuntimeException;

/**
 * A write the object's own rules refused.
 *
 * The message names the fields and says what was wrong with each. It used to
 * say only how MANY had failed — "Record validation failed for 1 field(s)" —
 * which is true, useless, and the exact text that reaches somebody debugging a
 * workflow, where the errors array is nowhere they can see it. Finding out
 * which field meant reproducing the write by hand somewhere the payload was
 * visible.
 */
class RecordValidationException extends RuntimeException
{
    /** Fields named in the message before it starts summarising. */
    private const NAMED = 4;

    /**
     * @param  array<string, list<string>>  $errors  field_slug → list of messages
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(self::describe($errors));
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function describe(array $errors): string
    {
        if ($errors === []) {
            return 'Record validation failed.';
        }

        $named = array_slice($errors, 0, self::NAMED, preserve_keys: true);

        $parts = [];
        foreach ($named as $slug => $messages) {
            // The field's own message when it has one — "Estado is required"
            // says more than "estado: invalid" ever will.
            $first = is_array($messages) ? ($messages[0] ?? null) : null;
            $parts[] = is_string($first) && $first !== ''
                ? $slug.': '.$first
                : (string) $slug;
        }

        $rest = count($errors) - count($named);

        return 'Record validation failed. '.implode(' ', $parts)
            .($rest > 0 ? " (and {$rest} more field(s))" : '');
    }
}
