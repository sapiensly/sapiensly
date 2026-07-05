<?php

namespace App\Services\Express;

/**
 * A phase deliberately stopping the pipeline with a user-facing explanation —
 * NOT a failure. The canonical case is the fit-check declaring the request's
 * core unanswerable by the connected source: building filler is forbidden, so
 * the run halts and the message proposes what the data CAN answer.
 */
class ExpressHalt extends \RuntimeException
{
    public function __construct(
        public readonly string $status,
        public readonly string $userMessage,
    ) {
        parent::__construct($userMessage);
    }
}
