<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\DemoDataGenerator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Fill an app with believable SAMPLE records so it is not empty on first open. This creates REAL records in the tenant — it is OPT-IN: only call it after the user has explicitly agreed to generate demo data (e.g. they answered yes to "want me to add sample data?"). Do NOT run it automatically after building an app. Records are seeded parent-before-child so relations link up; the records can be deleted later like any other.')]
class GenerateDemoDataTool extends SapiensTool
{
    protected const ABILITY = 'data:write';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'count' => ['nullable', 'integer', 'min:1', 'max:25'],
            'objects' => ['nullable', 'array'],
            'objects.*' => ['string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $manifest = app(AppManifestService::class)->getActiveManifest($app);
        if ($manifest === null || ($manifest['objects'] ?? []) === []) {
            return Response::error('This app has no objects to seed yet — add some first.');
        }

        $summary = app(DemoDataGenerator::class)->generate(
            $app,
            $manifest,
            $validated['count'] ?? 5,
            $validated['objects'] ?? null,
            $user,
        );

        return Response::json([
            'seeded' => true,
            'app_slug' => $app->slug,
            'created' => $summary,
            'total' => array_sum($summary),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app to seed.')->required(),
            'count' => $schema->integer()->description('How many records per object (default 5, max 25).'),
            'objects' => $schema->array()->description('Optional list of object slugs to seed; omit to seed every object.'),
        ];
    }
}
