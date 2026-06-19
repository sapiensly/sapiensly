<?php

namespace App\DTOs;

use App\Enums\ConnectorEffect;
use JsonSerializable;

/**
 * The typed capability contract for a single connector action.
 *
 * A Tool is one operation, so the mapping is 1 Tool ≈ 1 action. This contract
 * is what `list_connector_actions` exposes and what a `connector.call` step
 * composes against — the inputs/outputs it can reference, the effect that
 * decides whether it is gated, and the blast radius the user sees before
 * anything runs.
 *
 * @phpstan-type InputShape array{name: string, type: string, required: bool}
 */
class ConnectorActionContract implements JsonSerializable
{
    /**
     * @param  list<InputShape>  $inputs  Typed (function) or inferred (rest/graphql/database) parameters.
     * @param  list<string>  $outputs  Output paths addressable via {{steps.<id>.output.…}}; empty when untyped.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $integrationId,
        public readonly string $toolType,
        public readonly array $inputs,
        public readonly array $outputs,
        public readonly ConnectorEffect $effect,
        public readonly bool $effectInferred,
        public readonly string $blastRadius,
        public readonly bool $safe,
        public readonly bool $typed,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'integration_id' => $this->integrationId,
            'tool_type' => $this->toolType,
            'inputs' => $this->inputs,
            'outputs' => $this->outputs,
            'effect' => $this->effect->value,
            'effect_inferred' => $this->effectInferred,
            'blast_radius' => $this->blastRadius,
            'safe' => $this->safe,
            'typed' => $this->typed,
        ];
    }
}
