<?php

namespace App\Services\Integrations\Support;

use App\Models\IntegrationEnvironment;

/**
 * Resolves `{{var}}` templates against the active environment's variables,
 * with runtime overrides taking precedence. Mirrors the behavior of the
 * existing `SubstitutesParameters` trait used by the Tools runtime but
 * decrypts the encrypted `value` cast transparently and safely (unknown
 * tokens are left intact so the UI can flag them).
 */
class VariableResolver
{
    /**
     * Resolve a template string. Runtime vars win over stored env vars; both
     * win over never-set ($template left unchanged).
     *
     * @param  array<string, string>  $runtimeOverrides
     */
    public function resolve(
        string $template,
        array $runtimeOverrides = [],
        ?IntegrationEnvironment $environment = null,
    ): string {
        $map = $this->buildMap($environment, $runtimeOverrides);

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            fn (array $m) => array_key_exists($m[1], $map) ? $map[$m[1]] : $m[0],
            $template,
        );
    }

    /**
     * Resolve a JSON body template. String values substituted from variables
     * are JSON-escaped so a value containing quotes cannot break the surrounding
     * JSON document.
     *
     * @param  array<string, string>  $runtimeOverrides
     */
    public function resolveJson(
        string $template,
        array $runtimeOverrides = [],
        ?IntegrationEnvironment $environment = null,
    ): string {
        $map = $this->buildMap($environment, $runtimeOverrides);

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            function (array $m) use ($map): string {
                if (! array_key_exists($m[1], $map)) {
                    return $m[0];
                }
                $encoded = json_encode($map[$m[1]]);
                // Strip the surrounding quotes because the template already
                // contains them in "key": "{{var}}" contexts; callers that
                // want raw JSON-encoded values can do so by leaving quotes off.
                if (is_string($encoded) && strlen($encoded) >= 2 && $encoded[0] === '"') {
                    return substr($encoded, 1, -1);
                }

                return (string) $encoded;
            },
            $template,
        );
    }

    /**
     * Keys referenced as `{{var}}` in the template.
     *
     * @return array<int, string>
     */
    public function extractTokens(string $template): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $template, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @return array<string, string>
     */
    private function buildMap(?IntegrationEnvironment $environment, array $runtimeOverrides): array
    {
        $map = [];

        if ($environment !== null) {
            foreach ($environment->variables as $variable) {
                $map[$variable->key] = (string) $variable->value;
            }
        }

        foreach ($runtimeOverrides as $key => $value) {
            $map[$key] = (string) $value;
        }

        return $map;
    }
}
