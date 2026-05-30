<?php

namespace App\Ai\Tools\Builder;

use App\Services\Manifest\ManifestValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Runs the manifest validator on a draft manifest so Claude can self-check
 * before calling propose_change. Returns the structured error list — Claude
 * can iterate on it.
 */
class ValidateManifestTool implements Tool
{
    public function __construct(
        private ManifestValidator $validator,
    ) {}

    public function name(): string
    {
        return 'validate_manifest';
    }

    public function description(): string
    {
        return 'Validate a draft manifest against the JSON Schema and the cross-cutting rules (resolved references, unique slugs, compatible types). Returns {valid: bool, errors: [{path, message, code}]}.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'manifest' => $schema
                ->object()
                ->description('Full draft manifest object to validate.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $manifest = $args['manifest'] ?? [];

        if (! is_array($manifest)) {
            return json_encode([
                'valid' => false,
                'errors' => [['path' => '/', 'message' => 'manifest argument must be an object', 'code' => 'bad_input']],
            ], JSON_THROW_ON_ERROR);
        }

        $result = $this->validator->validate($manifest);

        return json_encode([
            'valid' => $result->valid,
            'errors' => $result->errorsArray(),
        ], JSON_THROW_ON_ERROR);
    }
}
