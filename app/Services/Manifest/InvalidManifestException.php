<?php

namespace App\Services\Manifest;

use RuntimeException;

class InvalidManifestException extends RuntimeException
{
    /** Cap on errors spelled out in the message; the rest are summarised. */
    private const MAX_DETAILED = 10;

    public function __construct(public readonly ManifestValidationResult $result)
    {
        parent::__construct(self::summarize($result));
    }

    /**
     * Spell out each failure as "path: message" so the model (and any caller
     * surfacing getMessage()) gets actionable detail, not just a count. The
     * validator's messages are written as authoring guidance — losing them here
     * is what leaves the AI guessing.
     */
    private static function summarize(ManifestValidationResult $result): string
    {
        $count = count($result->errors);
        if ($count === 0) {
            return 'Manifest validation failed.';
        }

        $lines = array_map(
            fn (ManifestValidationError $e): string => ($e->path !== '' ? $e->path.': ' : '').$e->message,
            array_slice($result->errors, 0, self::MAX_DETAILED),
        );
        $more = $count > self::MAX_DETAILED ? ' (+'.($count - self::MAX_DETAILED).' more)' : '';

        return 'Manifest validation failed with '.$count.' error(s): '.implode('; ', $lines).$more.'.';
    }
}
