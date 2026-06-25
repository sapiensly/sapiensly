<?php

namespace App\Services\Manifest;

class ManifestValidationError
{
    /**
     * @param  mixed  $expected  Machine-readable expectation (e.g. the allowed enum
     *                           values, the required type) when known, so a model can
     *                           diff it against what it sent instead of parsing the
     *                           prose message. Null when not applicable.
     * @param  mixed  $value  The offending scalar value the manifest carried at
     *                        `path`, when known. Null when not applicable.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $message,
        public readonly string $code,
        public readonly mixed $expected = null,
        public readonly mixed $value = null,
    ) {}

    /**
     * @return array{path: string, message: string, code: string, expected?: mixed, value?: mixed}
     */
    public function toArray(): array
    {
        $out = [
            'path' => $this->path,
            'message' => $this->message,
            'code' => $this->code,
        ];

        if ($this->expected !== null) {
            $out['expected'] = $this->expected;
        }
        if ($this->value !== null) {
            $out['value'] = $this->value;
        }

        return $out;
    }
}
