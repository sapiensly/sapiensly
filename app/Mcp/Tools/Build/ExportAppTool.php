<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Apps\AppPackage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Export an app as a PORTABLE PACKAGE (JSON) that can be installed into any account, or install one with `package`. What cannot travel is stripped and listed under portability.removed: a connected object keeps its fields but loses its live source, a workflow that calls an integration or agent is dropped whole, and a chatbot binding never travels. Every id is remapped on install, so two installs of the same package share no identifiers. Use `duplicate_of` to copy an app in place.')]
class ExportAppTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['sometimes', 'nullable', 'string'],
            'package' => ['sometimes', 'nullable', 'array'],
            'duplicate_of' => ['sometimes', 'nullable', 'string'],
            'name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'with_records' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $packages = app(AppPackage::class);

        try {
            if (! empty($validated['duplicate_of'])) {
                $result = $packages->duplicate(
                    $this->resolveApp($validated['duplicate_of'], $user),
                    $user,
                    $validated['name'] ?? null,
                    includeRecords: (bool) ($validated['with_records'] ?? false),
                );

                return Response::json([
                    'installed' => true,
                    'app_slug' => $result['app']->slug,
                    'name' => $result['app']->name,
                    'removed' => $result['notes'],
                ]);
            }

            if (! empty($validated['package'])) {
                $result = $packages->import($validated['package'], $user, $validated['name'] ?? null);

                return Response::json([
                    'installed' => true,
                    'app_slug' => $result['app']->slug,
                    'name' => $result['app']->name,
                    'removed' => $result['notes'],
                ]);
            }

            if (empty($validated['app_slug'])) {
                return Response::error('Pass `app_slug` to export, `package` to install, or `duplicate_of` to copy.');
            }

            return Response::json(
                $packages->export(
                    $this->resolveApp($validated['app_slug'], $user),
                    (bool) ($validated['with_records'] ?? false),
                ),
            );
        } catch (ModelNotFoundException) {
            return Response::error('No app by that slug is visible to you.');
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('Export this app as a package.'),
            'package' => $schema->object()->description('A package to install as a new app (the object a previous export returned).'),
            'duplicate_of' => $schema->string()->description('Copy this app in place — export and install in one step.'),
            'name' => $schema->string()->description('Name for the newly installed app. Defaults to the package\'s own.'),
            'with_records' => $schema->boolean()->description('Carry seed rows (bounded per object). Off by default — a package is a schema, and shipping a customer list inside one by accident is the mistake the wrong person discovers.'),
        ];
    }
}
