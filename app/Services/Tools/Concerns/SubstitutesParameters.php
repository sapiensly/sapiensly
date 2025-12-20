<?php

namespace App\Services\Tools\Concerns;

trait SubstitutesParameters
{
    /**
     * Substitute {{variable}} placeholders in a string with parameter values.
     */
    protected function substituteString(string $template, array $parameters): string
    {
        return preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            fn ($matches) => $this->getParameterValue($matches[1], $parameters),
            $template
        );
    }

    /**
     * Substitute placeholders in an array recursively.
     */
    protected function substituteArray(array $template, array $parameters): array
    {
        $result = [];

        foreach ($template as $key => $value) {
            $substitutedKey = is_string($key) ? $this->substituteString($key, $parameters) : $key;

            if (is_string($value)) {
                $result[$substitutedKey] = $this->substituteString($value, $parameters);
            } elseif (is_array($value)) {
                $result[$substitutedKey] = $this->substituteArray($value, $parameters);
            } else {
                $result[$substitutedKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Get a parameter value, with support for nested access using dot notation.
     */
    protected function getParameterValue(string $key, array $parameters): string
    {
        $value = data_get($parameters, $key, '');

        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    /**
     * Substitute :param style placeholders (for SQL).
     */
    protected function extractNamedParameters(string $template, array $parameters): array
    {
        preg_match_all('/:(\w+)/', $template, $matches);

        $bindings = [];
        foreach ($matches[1] as $param) {
            if (array_key_exists($param, $parameters)) {
                $bindings[$param] = $parameters[$param];
            }
        }

        return $bindings;
    }
}
