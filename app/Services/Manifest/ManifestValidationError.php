<?php

namespace App\Services\Manifest;

class ManifestValidationError
{
    public function __construct(
        public readonly string $path,
        public readonly string $message,
        public readonly string $code,
    ) {}

    /**
     * @return array{path: string, message: string, code: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'message' => $this->message,
            'code' => $this->code,
        ];
    }
}
