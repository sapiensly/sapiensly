<?php

namespace App\Services\Workflows;

use RuntimeException;
use Throwable;

class StepFailedException extends RuntimeException
{
    public function __construct(string $message, public readonly ?Throwable $cause = null)
    {
        parent::__construct($message, previous: $cause);
    }
}
