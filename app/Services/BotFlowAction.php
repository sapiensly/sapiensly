<?php

namespace App\Services;

use App\Enums\BotFlowActionType;

class BotFlowAction
{
    public function __construct(
        public readonly BotFlowActionType $type,
        public readonly array $data,
        public readonly array $updatedState,
    ) {}
}
