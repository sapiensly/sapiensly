<?php

namespace App\Services\Manifest;

use RuntimeException;

class InvalidManifestException extends RuntimeException
{
    public function __construct(public readonly ManifestValidationResult $result)
    {
        parent::__construct('Manifest validation failed with '.count($result->errors).' error(s).');
    }
}
