<?php

namespace App\Services\Connectors;

use App\DTOs\ConnectorActionContract;

/**
 * The outcome of inspecting a connector call: the resolved contract plus
 * whether the caller must gate (propose/refuse) rather than execute.
 */
final class ConnectorCallDecision
{
    public function __construct(
        public readonly ConnectorActionContract $contract,
        public readonly bool $mustGate,
    ) {}
}
