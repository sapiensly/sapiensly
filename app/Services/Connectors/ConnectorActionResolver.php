<?php

namespace App\Services\Connectors;

use App\DTOs\ConnectorActionContract;
use App\Enums\ConnectorEffect;
use App\Enums\ToolType;
use App\Models\Tool;

/**
 * Derives the typed connector-action contract for a Tool.
 *
 * A Tool is a single operation, so this is a 1 Tool -> 1 contract mapping. Two
 * things are not declared on the Tool today and must be derived here:
 *
 *  - effect (read/write): inferred from the tool type, HTTP method, GraphQL
 *    operation type or the database read_only flag, unless the author has
 *    pinned it via the `effect` override column.
 *  - typed inputs/outputs: lifted from `config.parameters` for `function`
 *    tools, inferred from the {{placeholder}} / :param templates otherwise.
 *    Where neither exists the action is still callable but marked untyped.
 *
 * Unknown types (e.g. mcp) default to a WRITE effect so they are gated until an
 * author confirms otherwise — never silently allow an ungated external write.
 */
class ConnectorActionResolver
{
    private const PLACEHOLDER_PATTERN = '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/';

    private const NAMED_PARAM_PATTERN = '/:([a-zA-Z_][a-zA-Z0-9_]*)/';

    public function resolve(Tool $tool): ConnectorActionContract
    {
        $config = $tool->config ?? [];

        [$effect, $effectInferred] = $this->resolveEffect($tool, $config);

        return new ConnectorActionContract(
            id: $tool->id,
            name: $tool->name,
            integrationId: $config['integration_id'] ?? null,
            toolType: $tool->type->value,
            inputs: $this->resolveInputs($tool->type, $config),
            outputs: $this->resolveOutputs($config),
            effect: $effect,
            effectInferred: $effectInferred,
            blastRadius: $this->describeBlastRadius($tool->type, $config, $effect),
            safe: (bool) $tool->safe,
            typed: $this->isTyped($tool->type, $config),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: ConnectorEffect, 1: bool} The effect and whether it was inferred (vs. author-pinned).
     */
    private function resolveEffect(Tool $tool, array $config): array
    {
        if ($tool->effect instanceof ConnectorEffect) {
            return [$tool->effect, false];
        }

        $derived = match ($tool->type) {
            ToolType::RestApi => $this->isReadMethod($config['method'] ?? 'GET')
                ? ConnectorEffect::Read
                : ConnectorEffect::Write,
            ToolType::Graphql => ($config['operation_type'] ?? 'query') === 'query'
                ? ConnectorEffect::Read
                : ConnectorEffect::Write,
            ToolType::Database => ! empty($config['read_only'])
                ? ConnectorEffect::Read
                : ConnectorEffect::Write,
            ToolType::Function, ToolType::Group => ConnectorEffect::Read,
            ToolType::Mcp => ConnectorEffect::Write,
        };

        return [$derived, true];
    }

    private function isReadMethod(string $method): bool
    {
        return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{name: string, type: string, required: bool}>
     */
    private function resolveInputs(ToolType $type, array $config): array
    {
        return match ($type) {
            ToolType::Function => $this->functionInputs($config),
            ToolType::Database => $this->namedParamInputs((string) ($config['query_template'] ?? '')),
            ToolType::RestApi => $this->placeholderInputs($this->restTemplateStrings($config)),
            ToolType::Graphql => $this->placeholderInputs($this->graphqlTemplateStrings($config)),
            ToolType::Mcp, ToolType::Group => [],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{name: string, type: string, required: bool}>
     */
    private function functionInputs(array $config): array
    {
        $parameters = $config['parameters'] ?? [];

        if (! is_array($parameters)) {
            return [];
        }

        // Standard JSON Schema object: { properties: {...}, required: [...] }.
        $properties = $parameters['properties'] ?? null;
        $required = (array) ($parameters['required'] ?? []);

        // Fall back to a flat map of name => schema when no `properties` key.
        if (! is_array($properties)) {
            $properties = array_filter($parameters, 'is_array');
            $required = [];
        }

        $inputs = [];

        foreach ($properties as $name => $schema) {
            $inputs[] = [
                'name' => (string) $name,
                'type' => is_array($schema) ? (string) ($schema['type'] ?? 'string') : 'string',
                'required' => in_array($name, $required, true),
            ];
        }

        return array_values($inputs);
    }

    /**
     * @return list<array{name: string, type: string, required: bool}>
     */
    private function namedParamInputs(string $template): array
    {
        preg_match_all(self::NAMED_PARAM_PATTERN, $template, $matches);

        return $this->toRequiredStringInputs($matches[1] ?? []);
    }

    /**
     * @param  list<string>  $strings
     * @return list<array{name: string, type: string, required: bool}>
     */
    private function placeholderInputs(array $strings): array
    {
        $names = [];

        foreach ($strings as $string) {
            preg_match_all(self::PLACEHOLDER_PATTERN, $string, $matches);
            $names = array_merge($names, $matches[1] ?? []);
        }

        return $this->toRequiredStringInputs($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<array{name: string, type: string, required: bool}>
     */
    private function toRequiredStringInputs(array $names): array
    {
        return array_values(array_map(
            fn (string $name): array => ['name' => $name, 'type' => 'string', 'required' => true],
            array_values(array_unique($names)),
        ));
    }

    /**
     * Canonical keys (path, request_body_template, headers) plus the legacy
     * aliases (endpoint, body_template, query_params) so inputs are not missed
     * regardless of which write path created the tool.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function restTemplateStrings(array $config): array
    {
        return $this->flattenStrings([
            $config['path'] ?? null,
            $config['endpoint'] ?? null,
            $config['request_body_template'] ?? null,
            $config['body_template'] ?? null,
            $config['query_params'] ?? null,
            $config['query'] ?? null,
            $config['headers'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function graphqlTemplateStrings(array $config): array
    {
        return $this->flattenStrings([
            $config['operation'] ?? null,
            $config['query'] ?? null,
            $config['variables_template'] ?? null,
        ]);
    }

    /**
     * Collect every string leaf from a mixed set of template values.
     *
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function flattenStrings(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            } elseif (is_array($value)) {
                $strings = array_merge($strings, $this->flattenStrings(array_values($value)));
            }
        }

        return $strings;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function resolveOutputs(array $config): array
    {
        $mapping = $config['response_mapping'] ?? null;

        if (! is_array($mapping) || $mapping === []) {
            return [];
        }

        return array_values(array_map('strval', array_keys($mapping)));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function isTyped(ToolType $type, array $config): bool
    {
        return $type === ToolType::Function && ! empty($config['parameters']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function describeBlastRadius(ToolType $type, array $config, ConnectorEffect $effect): string
    {
        $verb = $effect->isWrite() ? 'May write to' : 'Reads from';

        return match ($type) {
            ToolType::RestApi => sprintf(
                '%s %s%s via %s',
                $verb,
                $this->host($config['base_url'] ?? $config['endpoint'] ?? ''),
                $config['path'] ?? '',
                strtoupper((string) ($config['method'] ?? 'GET')),
            ),
            ToolType::Graphql => sprintf(
                '%s the GraphQL endpoint %s',
                $verb,
                $this->host($config['endpoint'] ?? ''),
            ),
            ToolType::Database => sprintf(
                '%s the %s database',
                $verb,
                $config['database'] ?? 'configured',
            ),
            ToolType::Mcp => sprintf(
                '%s the MCP server %s',
                $verb,
                $this->host($config['endpoint'] ?? ''),
            ),
            ToolType::Function => 'Runs a sandboxed computation (no external system)',
            ToolType::Group => 'Invokes a group of tools',
        };
    }

    private function host(string $url): string
    {
        if ($url === '') {
            return 'an external system';
        }

        return parse_url($url, PHP_URL_HOST) ?: $url;
    }
}
