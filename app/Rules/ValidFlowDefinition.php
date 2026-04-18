<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidFlowDefinition implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The flow definition must be a valid object.');

            return;
        }

        $nodes = $value['nodes'] ?? [];
        $edges = $value['edges'] ?? [];

        if (! is_array($nodes) || ! is_array($edges)) {
            $fail('The flow definition must contain nodes and edges arrays.');

            return;
        }

        // Validate at least one start node
        $startNodes = array_filter($nodes, fn ($n) => ($n['type'] ?? null) === 'start');
        if (count($startNodes) === 0) {
            $fail('The flow must have exactly one start node.');

            return;
        }
        if (count($startNodes) > 1) {
            $fail('The flow must have exactly one start node.');

            return;
        }

        // Collect valid node IDs
        $nodeIds = [];
        foreach ($nodes as $node) {
            if (! isset($node['id']) || ! isset($node['type'])) {
                $fail('Each node must have an id and type.');

                return;
            }
            $nodeIds[] = $node['id'];
        }

        // Validate edges reference valid nodes
        foreach ($edges as $edge) {
            if (! isset($edge['id'], $edge['source'], $edge['target'])) {
                $fail('Each edge must have an id, source, and target.');

                return;
            }

            if (! in_array($edge['source'], $nodeIds)) {
                $fail("Edge {$edge['id']} references non-existent source node {$edge['source']}.");

                return;
            }

            if (! in_array($edge['target'], $nodeIds)) {
                $fail("Edge {$edge['id']} references non-existent target node {$edge['target']}.");

                return;
            }
        }

        // Validate menu nodes have at least one option
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'menu') {
                $options = $node['data']['options'] ?? [];
                if (empty($options)) {
                    $fail("Menu node {$node['id']} must have at least one option.");

                    return;
                }
            }
        }

        // Validate valid node types
        $validTypes = ['start', 'menu', 'condition', 'agent_handoff', 'message', 'connector', 'end'];
        foreach ($nodes as $node) {
            if (! in_array($node['type'], $validTypes)) {
                $fail("Node {$node['id']} has invalid type {$node['type']}.");

                return;
            }
        }
    }
}
