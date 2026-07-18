<?php

namespace App\Mcp\Tools\Build;

use App\Enums\Visibility;
use App\Mcp\Tools\SapiensTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Records\RecordValidationException;
use App\Services\Records\RecordWriteService;
use App\Support\Locale\Inflector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a COMPLETE working app from a plain-language description in ONE step — the easy alternative to create_app + a long chain of propose_change patches. It generates the data objects and their fields, the belongs-to relations between them (e.g. a draft belongs to one idea), a ready-to-use list+create page for each (with a kanban board when the object has a status field, and a Gantt when it has start+end dates), and a dashboard landing page with KPIs and a status chart — saved as version 1. Pass `seed_records` to open the app already populated (e.g. a plan\'s tasks). Use this to start any new app; then refine details with the add_object / add_field / add_relation tools or propose_change.')]
class ScaffoldAppTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'slug' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:50'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'visibility' => ['nullable', Rule::enum(Visibility::class)],
            'idempotency_key' => ['nullable', 'string', 'max:200'],
            'seed_records' => ['nullable', 'array', 'max:20'],
            'seed_records.*' => ['array'],
            'seed_records.*.object' => ['required', 'string', 'max:100'],
            'seed_records.*.records' => ['required', 'array', 'min:1', 'max:100'],
            'seed_records.*.records.*' => ['array'],
        ]);

        // A scaffold is an expensive LLM call; replay a prior result for this key
        // instead of generating (and billing) a second near-duplicate app.
        if (($replay = $this->idempotentReplay($user, $validated['idempotency_key'] ?? null)) !== null) {
            return Response::json($replay);
        }

        $slug = $validated['slug'] ?? $this->deriveSlug($validated['name'], $user);
        if ($slug === null) {
            return Response::error('Could not derive a unique slug from the name; pass an explicit `slug`.');
        }

        if (App::query()->forAccountContext($user)->where('slug', $slug)->exists()) {
            return Response::error("An app with slug '{$slug}' already exists in your account. Pass a different `slug`.");
        }

        $manifestService = app(AppManifestService::class);

        $app = App::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'slug' => $slug,
            'name' => $validated['name'],
            'description' => $validated['description'],
            'icon' => $validated['icon'] ?? null,
            'color' => $validated['color'] ?? null,
            'visibility' => isset($validated['visibility']) ? Visibility::from($validated['visibility']) : Visibility::Private,
        ]);

        try {
            $manifest = app(AppScaffolder::class)->scaffold(
                $manifestService->initialManifest($app),
                $validated['description'],
                $user,
            );

            $version = $manifestService->createVersion($app, $manifest, $user, 'Scaffolded from description');
        } catch (\Throwable $e) {
            // Roll back the orphaned app row so a failed scaffold leaves nothing behind.
            $app->delete();

            return Response::error('The app could not be scaffolded: '.$e->getMessage());
        }

        // Seed AFTER the version is saved: a bad row must never cost the app.
        $seeded = empty($validated['seed_records'])
            ? []
            : $this->seedRecords($app, $manifest, array_values($validated['seed_records']), $user);

        $payload = [
            'created' => true,
            'app_slug' => $app->slug,
            'app_id' => $app->id,
            'name' => $app->name,
            'version_number' => $version->version_number,
            'objects' => array_map(fn (array $o): array => [
                'slug' => $o['slug'],
                'name' => $o['name'],
                'fields' => count($o['fields']),
            ], $manifest['objects']),
            'pages' => array_map(fn (array $p): string => $p['path'], $manifest['pages']),
            'url' => route('apps.runtime', ['app_slug' => $app->slug]),
            'next' => 'Open the app to use it, or refine it with read_manifest + propose_change.',
        ];
        if ($seeded !== []) {
            $payload['seeded'] = $seeded;
        }
        $this->rememberIdempotent($user, $validated['idempotency_key'] ?? null, $payload);

        return Response::json($payload);
    }

    /**
     * Derive a unique, schema-valid slug from the app name, suffixing on collision.
     */
    private function deriveSlug(string $name, User $user): ?string
    {
        $base = trim((string) preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower(Str::ascii($name))), '_');
        if ($base === '' || ! preg_match('/^[a-z]/', $base)) {
            $base = 'app_'.$base;
        }
        $base = (string) Str::limit($base, 40, '');

        $slug = $base;
        $n = 2;
        while (App::query()->forAccountContext($user)->where('slug', $slug)->exists()) {
            if ($n > 50) {
                return null;
            }
            $slug = $base.'_'.$n++;
        }

        return $slug;
    }

    /**
     * Insert the caller's seed rows once the schema exists. The rows were
     * authored BEFORE the scaffold ran, so their object / field / option
     * identifiers are the caller's best guess at the generated slugs — resolve
     * them tolerantly (exact slug, else accent/case-insensitive name match) and
     * report per-row failures instead of aborting: a partially seeded app beats
     * an empty one, and the caller sees exactly which rows to retry.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<array<string, mixed>>  $groups
     * @return list<array{object: string, created: int, errors: list<string>}>
     */
    private function seedRecords(App $app, array $manifest, array $groups, User $user): array
    {
        $writer = app(RecordWriteService::class);
        $objects = array_values(array_filter((array) ($manifest['objects'] ?? []), is_array(...)));
        $summary = [];

        foreach ($groups as $group) {
            $needle = (string) ($group['object'] ?? '');
            $object = $this->matchObject($objects, $needle);
            if ($object === null) {
                $summary[] = [
                    'object' => $needle,
                    'created' => 0,
                    'errors' => ["No scaffolded object matched '{$needle}'. Available: ".implode(', ', array_column($objects, 'slug')).'.'],
                ];

                continue;
            }

            $created = 0;
            $errors = [];
            foreach (array_values((array) $group['records']) as $i => $values) {
                try {
                    $writer->create($app, $manifest, $object['id'], $this->remapRow($object, (array) $values), $user);
                    $created++;
                } catch (RecordValidationException $e) {
                    $detail = [];
                    foreach ($e->errors as $slug => $messages) {
                        $detail[] = $slug.': '.implode(' ', $messages);
                    }
                    $errors[] = "Row {$i}: ".implode(' | ', $detail);
                } catch (\Throwable $e) {
                    $errors[] = "Row {$i}: ".$e->getMessage();
                }
            }
            $summary[] = ['object' => (string) $object['slug'], 'created' => $created, 'errors' => $errors];
        }

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $objects
     * @return array<string, mixed>|null
     */
    private function matchObject(array $objects, string $needle): ?array
    {
        foreach ($objects as $object) {
            if (($object['id'] ?? null) === $needle || ($object['slug'] ?? null) === $needle) {
                return $object;
            }
        }

        // The seed labels come from the prompt ("Companies") but the model names
        // the object however it likes ("Company"), so match across the
        // singular/plural boundary — in English (Str) and Spanish (Inflector) — not
        // just on an exact normalized token.
        $needleForms = $this->tokenForms($needle);
        foreach ($objects as $object) {
            $objectForms = array_merge(
                $this->tokenForms((string) ($object['slug'] ?? '')),
                $this->tokenForms((string) ($object['name'] ?? '')),
            );
            if (array_intersect($needleForms, $objectForms) !== []) {
                return $object;
            }
        }

        return null;
    }

    /**
     * The normalized token for a name plus its singular/plural variants, so
     * "Companies", "Company" and "company" all resolve to one another regardless
     * of which side is pluralized.
     *
     * @return list<string>
     */
    private function tokenForms(string $value): array
    {
        $forms = [
            $this->normalizeToken($value),
            $this->normalizeToken((string) Str::singular($value)),
            $this->normalizeToken((string) Str::plural($value)),
            $this->normalizeToken(Inflector::singular($value, 'es')),
        ];

        return array_values(array_unique(array_filter($forms, fn (string $f): bool => $f !== '')));
    }

    /**
     * Rekey a seed row onto the object's real field slugs and snap select
     * values onto real option values (matching by option value OR label).
     * Unmatched keys/values pass through untouched so RecordWriteService's
     * validation reports them instead of us silently dropping data.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function remapRow(array $object, array $values): array
    {
        $fields = [];
        foreach ((array) ($object['fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }
            $fields[$this->normalizeToken((string) $field['slug'])] ??= $field;
            $fields[$this->normalizeToken((string) ($field['name'] ?? ''))] ??= $field;
        }

        $row = [];
        foreach ($values as $key => $value) {
            $field = $fields[$this->normalizeToken((string) $key)] ?? null;
            if ($field === null) {
                $row[$key] = $value;

                continue;
            }
            if (in_array($field['type'] ?? '', ['single_select', 'multi_select'], true)) {
                $value = is_array($value)
                    ? array_map(fn ($v) => $this->matchOption($field, $v), $value)
                    : $this->matchOption($field, $value);
            }
            $row[(string) $field['slug']] = $value;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function matchOption(array $field, mixed $raw): mixed
    {
        if (! is_string($raw) && ! is_numeric($raw)) {
            return $raw;
        }
        $norm = $this->normalizeToken((string) $raw);
        foreach ((array) ($field['options'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }
            if ($this->normalizeToken((string) ($option['value'] ?? '')) === $norm
                || $this->normalizeToken((string) ($option['label'] ?? '')) === $norm) {
                return $option['value'];
            }
        }

        return $raw;
    }

    /**
     * Accent/case/punctuation-insensitive comparison token ("En curso" ≡ "en_curso").
     */
    private function normalizeToken(string $value): string
    {
        return (string) Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The app name (max 100 chars), e.g. "Content Engine".')->required(),
            'description' => $schema->string()->description('Plain-language description of what the app should track and do — the entities, their fields, and the workflow. The richer this is, the better the generated objects and pages.')->required(),
            'slug' => $schema->string()->description('Optional URL-safe id (^[a-z][a-z0-9_]*$). Derived from the name when omitted.'),
            'icon' => $schema->string()->description('Optional icon name.'),
            'color' => $schema->string()->description('Optional hex accent color (#RRGGBB).'),
            'visibility' => $schema->string()->enum(array_column(Visibility::cases(), 'value'))->description('private (default), organization, global, or public.'),
            'idempotency_key' => $schema->string()->description('Optional. A unique client token; retrying with the same key replays the original result instead of scaffolding a second app (safe retry after a timeout — and avoids a duplicate LLM call).'),
            'seed_records' => $schema->array()->description('Optional initial data, inserted right after the app is generated — use it so a plan/tracker app opens populated instead of empty. Array of {object, records}: `object` names one of the entities from your description (its name or snake_case slug); `records` is up to 100 rows, each a {field: value} map keyed by the field names/slugs from your description. Dates in ISO (YYYY-MM-DD), selects by option label or value, booleans as true/false. Rows that fail validation are reported back without blocking the app.'),
        ];
    }
}
