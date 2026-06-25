<?php

namespace App\Services\Manifest;

class ManifestValidationResult
{
    /**
     * @param  ManifestValidationError[]  $errors
     * @param  ManifestValidationError[]  $warnings  Non-blocking advisories (e.g. a
     *                                               control that has no effect). The
     *                                               manifest is still `valid`.
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * @param  ManifestValidationError[]  $warnings
     */
    public static function ok(array $warnings = []): self
    {
        return new self(true, [], $warnings);
    }

    /**
     * @param  ManifestValidationError[]  $errors
     * @param  ManifestValidationError[]  $warnings
     */
    public static function fail(array $errors, array $warnings = []): self
    {
        return new self(false, $errors, $warnings);
    }

    /**
     * @return list<array{path: string, message: string, code: string, expected?: mixed, value?: mixed}>
     */
    public function errorsArray(): array
    {
        return array_map(fn (ManifestValidationError $e) => $e->toArray(), $this->errors);
    }

    /**
     * @return list<array{path: string, message: string, code: string, expected?: mixed, value?: mixed}>
     */
    public function warningsArray(): array
    {
        return array_map(fn (ManifestValidationError $w) => $w->toArray(), $this->warnings);
    }
}
