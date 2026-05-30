<?php

namespace App\Services\Records;

use RuntimeException;

class RecordValidationException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  field_slug → list of messages
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Record validation failed for '.count($errors).' field(s).');
    }
}
