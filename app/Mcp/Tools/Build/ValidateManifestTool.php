<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Validate a draft app manifest (schema + semantic rules) WITHOUT applying it. Returns errors and warnings so you can fix a manifest before propose_change.')]
class ValidateManifestTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'manifest' => ['required', 'array'],
        ]);

        $result = app(ManifestValidator::class)->validate($validated['manifest']);

        return Response::json([
            'valid' => $result->valid,
            'errors' => $result->errorsArray(),
            'warnings' => $result->warningsArray(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'manifest' => $schema->object()->description('The full draft manifest to validate.')->required(),
        ];
    }
}
