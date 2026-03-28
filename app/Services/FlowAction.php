<?php

namespace App\Services;

use App\Enums\FlowActionType;

class FlowAction
{
    public function __construct(
        public readonly FlowActionType $type,
        public readonly array $data,
        public readonly array $updatedState,
    ) {}
}
