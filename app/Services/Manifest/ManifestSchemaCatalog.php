<?php

namespace App\Services\Manifest;

/**
 * Resolves the authoritative JSON-Schema contract for a single catalog entry —
 * a field type, component (block), action, step or trigger — so the "what can I
 * build" catalogs can hand a model the exact parameters (required/optional names,
 * allowed enum values) and a worked skeleton instead of prose it has to guess
 * from. The schema (storage/app/schemas/app-manifest/v1.json) stays the single
 * source of truth: this reads it, it never restates it.
 */
class ManifestSchemaCatalog
{
    /** Cap on nested-object recursion when building an example skeleton. */
    private const MAX_DEPTH = 3;

    /** @var array<string, mixed>|null */
    private ?array $defs = null;

    public function __construct(private readonly ManifestValidator $validator) {}

    /**
     * The $defs name that backs a catalog entry — for fields/components a model can
     * pass it straight to get_manifest_schema; for action/step/trigger it's the
     * union definition (the whole oneOf).
     */
    public function definitionName(string $category, string $type): string
    {
        return match ($category) {
            'field' => 'field_'.$type,
            'component', 'block' => 'block_'.$type,
            default => $category,
        };
    }

    /**
     * Compact parameter contract for an entry: the required and optional property
     * names, plus the allowed values for each constrained (enum/const) property —
     * the detail that stops a model guessing nesting and enum spellings. Null when
     * no matching definition exists.
     *
     * @return array{required: list<string>, optional: list<string>, values: array<string, list<mixed>>}|null
     */
    public function params(string $category, string $type): ?array
    {
        $schema = $this->subSchema($category, $type);
        if ($schema === null) {
            return null;
        }

        $object = $this->flatten($schema, []);
        $required = array_values(array_unique($object['required']));
        $optional = array_values(array_diff(array_keys($object['properties']), $required));

        $values = [];
        foreach ($object['properties'] as $name => $propSchema) {
            $allowed = $this->allowedValues($propSchema);
            if ($allowed !== null) {
                $values[$name] = $allowed;
            }
        }

        return ['required' => $required, 'optional' => $optional, 'values' => $values];
    }

    /**
     * A minimal example instance built from the required properties — a SKELETON:
     * enum/const props get a real allowed value, everything else gets a typed
     * placeholder (`<name>` / `<id>` / 0 / false) the caller must fill in. Null
     * when no matching definition exists.
     */
    public function example(string $category, string $type): mixed
    {
        $schema = $this->subSchema($category, $type);
        if ($schema === null) {
            return null;
        }

        return $this->instantiate($schema, $type, 0, []);
    }

    /**
     * The raw sub-schema for a catalog entry. Fields/components are named $defs;
     * actions/steps/triggers are oneOf branches discriminated by their `type`.
     *
     * @return array<string, mixed>|null
     */
    public function subSchema(string $category, string $type): ?array
    {
        $defs = $this->defs();

        return match ($category) {
            'field' => $defs['field_'.$type] ?? null,
            'component', 'block' => $defs['block_'.$type] ?? null,
            'action', 'step', 'trigger' => $this->branchFor($defs[$category] ?? [], $type),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function defs(): array
    {
        return $this->defs ??= ($this->validator->schemaArray()['$defs'] ?? []);
    }

    /**
     * Find the oneOf branch whose `type` discriminator (a const, or an enum of
     * several event types) accepts the requested type.
     *
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>|null
     */
    private function branchFor(array $def, string $type): ?array
    {
        foreach ($def['oneOf'] ?? [] as $branch) {
            if (in_array($type, $this->branchTypes($branch), true)) {
                return $branch;
            }
        }

        return null;
    }

    /**
     * The type values a branch accepts — directly on `properties.type` or via an
     * allOf part (steps compose a `step_base` ref with the type const).
     *
     * @param  array<string, mixed>  $branch
     * @return list<mixed>
     */
    private function branchTypes(array $branch): array
    {
        $types = $this->typeValues($branch['properties']['type'] ?? null);
        if ($types !== []) {
            return $types;
        }

        foreach ($branch['allOf'] ?? [] as $part) {
            $types = $this->typeValues(is_array($part) ? ($part['properties']['type'] ?? null) : null);
            if ($types !== []) {
                return $types;
            }
        }

        return [];
    }

    /**
     * @return list<mixed>
     */
    private function typeValues(mixed $typeSchema): array
    {
        if (! is_array($typeSchema)) {
            return [];
        }
        if (array_key_exists('const', $typeSchema)) {
            return [$typeSchema['const']];
        }
        if (isset($typeSchema['enum']) && is_array($typeSchema['enum'])) {
            return $typeSchema['enum'];
        }

        return [];
    }

    /**
     * Flatten a schema node into a single object's merged {properties, required},
     * resolving $ref and allOf composition. A property declared `true` (defer to a
     * base) never overwrites a richer base definition.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $seen  $ref names already expanded (cycle guard)
     * @return array{properties: array<string, mixed>, required: list<string>}
     */
    private function flatten(array $schema, array $seen): array
    {
        if (isset($schema['$ref'])) {
            $name = $this->refName($schema['$ref']);
            if ($name === null || in_array($name, $seen, true)) {
                return ['properties' => [], 'required' => []];
            }

            return $this->flatten($this->defs()[$name] ?? [], [...$seen, $name]);
        }

        $properties = [];
        $required = [];

        foreach ($schema['allOf'] ?? [] as $part) {
            if (! is_array($part)) {
                continue;
            }
            $flat = $this->flatten($part, $seen);
            foreach ($flat['properties'] as $name => $propSchema) {
                if ($propSchema === true && isset($properties[$name])) {
                    continue;
                }
                $properties[$name] = $propSchema;
            }
            $required = array_merge($required, $flat['required']);
        }

        foreach ($schema['properties'] ?? [] as $name => $propSchema) {
            if ($propSchema === true && isset($properties[$name])) {
                continue;
            }
            $properties[$name] = $propSchema;
        }
        $required = array_merge($required, $schema['required'] ?? []);

        return ['properties' => $properties, 'required' => $required];
    }

    /**
     * The allowed values for a property schema (const → one, enum → all),
     * resolving a single level of $ref. Null when the property isn't constrained.
     *
     * @return list<mixed>|null
     */
    private function allowedValues(mixed $propSchema): ?array
    {
        if (! is_array($propSchema)) {
            return null;
        }
        if (isset($propSchema['$ref'])) {
            $name = $this->refName($propSchema['$ref']);
            $propSchema = $name !== null ? ($this->defs()[$name] ?? []) : [];
        }
        if (array_key_exists('const', $propSchema)) {
            return [$propSchema['const']];
        }
        if (isset($propSchema['enum']) && is_array($propSchema['enum'])) {
            return $propSchema['enum'];
        }

        return null;
    }

    /**
     * @param  list<string>  $seen
     */
    private function instantiate(mixed $node, ?string $forcedType, int $depth, array $seen): mixed
    {
        if ($depth > self::MAX_DEPTH || ! is_array($node)) {
            return null;
        }

        if (isset($node['$ref'])) {
            $name = $this->refName($node['$ref']);
            if ($name === null || in_array($name, $seen, true)) {
                return null;
            }

            return $this->instantiate($this->defs()[$name] ?? [], $forcedType, $depth, [...$seen, $name]);
        }

        if (isset($node['allOf'])) {
            $flat = $this->flatten($node, $seen);

            return $this->instantiateObject($flat['properties'], $flat['required'], $forcedType, $depth, $seen);
        }
        if (isset($node['oneOf'])) {
            return $this->instantiate($node['oneOf'][0] ?? [], $forcedType, $depth, $seen);
        }
        if (array_key_exists('const', $node)) {
            return $node['const'];
        }
        if (isset($node['enum']) && is_array($node['enum'])) {
            return $node['enum'][0] ?? null;
        }

        $jsonType = $node['type'] ?? null;
        if ($jsonType === 'object' || isset($node['properties'])) {
            return $this->instantiateObject($node['properties'] ?? [], $node['required'] ?? [], $forcedType, $depth, $seen);
        }
        if ($jsonType === 'array') {
            $item = $this->instantiate($node['items'] ?? [], null, $depth + 1, $seen);

            return $item === null ? [] : [$item];
        }

        return match ($jsonType) {
            'integer', 'number' => 0,
            'boolean' => false,
            default => '...',
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @param  list<string>  $seen
     * @return array<string, mixed>
     */
    private function instantiateObject(array $properties, array $required, ?string $forcedType, int $depth, array $seen): array
    {
        $out = [];
        foreach (array_unique($required) as $name) {
            if ($name === 'type' && $forcedType !== null) {
                $out['type'] = $forcedType;

                continue;
            }

            $propSchema = $properties[$name] ?? null;
            if ($propSchema === true || $propSchema === null) {
                $out[$name] = $this->placeholder($name);

                continue;
            }

            $value = $this->instantiate($propSchema, null, $depth + 1, $seen);
            $out[$name] = $value ?? $this->placeholder($name);
        }

        return $out;
    }

    private function placeholder(string $name): string
    {
        return str_ends_with($name, '_id') ? '<id>' : '<'.$name.'>';
    }

    private function refName(string $ref): ?string
    {
        if (! str_starts_with($ref, '#/$defs/')) {
            return null;
        }

        return substr($ref, strlen('#/$defs/'));
    }
}
