<?php

use App\Services\Manifest\ManifestValidator;
use App\Support\Locale\Inflector;
use Illuminate\Support\Str;

/**
 * The shipped templates are EXPORTS — snapshots frozen at the moment someone
 * ran them through the scaffolder. That is what makes them trustworthy (they
 * travel the same portable path as any uploaded package) and also what makes
 * them rot: a guarantee added to the scaffolder afterwards does not reach them,
 * and an app started from a template silently misses it.
 *
 * It happened. The templates were exported the day before the scaffolder began
 * marking a record's title required and defaulting its status, so a ticket
 * created from the help-desk template landed with no status at all — into a
 * nameless fifth kanban column, on a board whose four real columns stayed
 * empty. Nothing was broken enough to fail: the manifest validated and the
 * write succeeded.
 *
 * So the bar lives here rather than in the exporter. These assertions are about
 * what a person gets when they click a template, and each one below is a thing
 * that was actually missing from a shipped file.
 *
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function shippedTemplates(): array
{
    $out = [];
    foreach (glob(dirname(__DIR__, 4).'/resources/app-templates/*.json') as $path) {
        $package = json_decode((string) file_get_contents($path), true);
        $out[] = [basename($path), $package];
    }

    return $out;
}

it('ships at least one starter template', function () {
    expect(shippedTemplates())->not->toBeEmpty();
});

it('every template manifest is schema-valid', function () {
    foreach (shippedTemplates() as [$name, $package]) {
        // id/slug are assigned by the import, so supply what it will.
        $manifest = [
            ...$package['manifest'],
            'id' => 'app_'.strtolower((string) Str::ulid()),
            'slug' => 'tpl_check',
        ];

        $result = (new ManifestValidator)->validate($manifest);
        $errors = collect($result->errors)->map(fn ($e) => $e->path.': '.$e->message)->all();

        expect($errors)->toBe([], "{$name} is not schema-valid");
    }
});

it('gives every object a required title and a defaulted status', function () {
    foreach (shippedTemplates() as [$name, $package]) {
        foreach ($package['manifest']['objects'] as $object) {
            $fields = collect($object['fields']);

            // A record nobody can identify is a blank row in every list.
            $title = $fields->first(fn (array $f): bool => $f['type'] === 'string') ?? $fields->first();
            expect($title['required'] ?? false)
                ->toBeTrue("{$name}: {$object['slug']}.{$title['slug']} should be required");

            // A status with no default drops the new record out of its board.
            $status = $fields->first(fn (array $f): bool => $f['type'] === 'single_select');
            if ($status !== null) {
                expect($status['default'] ?? null)
                    ->toBe($status['options'][0]['value'], "{$name}: {$object['slug']}.{$status['slug']} needs a default");
            }
        }
    }
});

it('lets a user change a record, not only create one', function () {
    foreach (shippedTemplates() as [$name, $package]) {
        foreach ($package['manifest']['pages'] as $page) {
            $blocks = collect($page['blocks']);

            // Scoped to LIST pages — the ones that list records in a `table`.
            // A detail page also offers a create form (its "add a child"
            // modal), but its children render in a `related_list`, which is
            // read-only by design and has no action column to hang an edit on.
            // Editing a child in place needs a block-level change, not a wiring
            // one, so that path is out of scope here rather than asserted away.
            if (! $blocks->contains(fn (array $b): bool => ($b['type'] ?? null) === 'table')) {
                continue;
            }

            $modalForms = $blocks
                ->filter(fn (array $b): bool => ($b['type'] ?? null) === 'modal')
                ->map(fn (array $b): array => $b['blocks'][0] ?? []);

            $created = $modalForms->filter(fn (array $f): bool => ($f['mode'] ?? null) === 'create')->pluck('object_id');
            if ($created->isEmpty()) {
                continue;
            }

            $edited = $modalForms->filter(fn (array $f): bool => ($f['mode'] ?? null) === 'edit')->pluck('object_id');

            foreach ($created as $objectId) {
                expect($edited->contains($objectId))
                    ->toBeTrue("{$name}: page '{$page['slug']}' can create {$objectId} but never edit it");
            }
        }
    }
});

it('seeds every edit modal with the row it opens on', function () {
    // An edit modal opened without the row's values renders blank inputs, and
    // a blank edit is how a save wipes the fields nobody retyped. The id alone
    // is not enough: it tells the save WHICH record, not what it currently is.
    foreach (shippedTemplates() as [$name, $package]) {
        foreach ($package['manifest']['pages'] as $page) {
            $editModalIds = collect($page['blocks'])
                ->filter(fn (array $b): bool => ($b['type'] ?? null) === 'modal'
                    && (($b['blocks'][0]['mode'] ?? null) === 'edit'))
                ->pluck('id');

            foreach ($editModalIds as $modalId) {
                $opens = collect($page['blocks'])
                    ->flatMap(fn (array $b): array => $b['columns'] ?? [])
                    ->flatMap(fn (array $c): array => $c['on_click'] ?? [])
                    ->filter(fn (array $a): bool => ($a['type'] ?? null) === 'open_modal'
                        && ($a['modal_block_id'] ?? null) === $modalId);

                expect($opens)->not->toBeEmpty("{$name}: nothing opens edit modal {$modalId}");

                foreach ($opens as $open) {
                    expect($open['params']['record_id'] ?? null)->toBe('{{row.id}}', "{$name}: {$modalId} opened without a record id");
                    expect($open['params']['record'] ?? null)->toBe('{{row.data}}', "{$name}: {$modalId} opened without the row's values");
                }
            }
        }
    }
});

it('gives every role something it may actually do', function () {
    // Roles the picker offers but that carry no object policy are decoration:
    // the app looks permissioned and grants nothing.
    foreach (shippedTemplates() as [$name, $package]) {
        $manifest = $package['manifest'];
        $policies = collect($manifest['permissions']['object_policies'] ?? []);
        $portal = array_filter([
            $manifest['permissions']['public']['role_id'] ?? null,
            $manifest['permissions']['public']['member_role_id'] ?? null,
        ]);

        foreach ($manifest['permissions']['roles'] ?? [] as $role) {
            if (in_array($role['id'], $portal, true)) {
                continue;
            }
            foreach ($manifest['objects'] as $object) {
                $policy = $policies->first(
                    fn (array $p): bool => $p['role_id'] === $role['id'] && $p['object_id'] === $object['id']
                );
                expect($policy)->not->toBeNull("{$name}: role '{$role['slug']}' has no policy on '{$object['slug']}'");

                // At least one action, rather than `read` specifically. This
                // asked for read until the survey template, where an employee
                // may CREATE a submission, its answers and their participation
                // marker and may not read any of them back — the whole promise
                // of an anonymous questionnaire is that nobody can list what
                // everybody said. A write-only policy is not the decoration
                // this guard exists to catch; it grants something real.
                expect($policy['actions'])
                    ->not->toBeEmpty("{$name}: role '{$role['slug']}' has an empty policy on '{$object['slug']}'");
            }
        }
    }
});

/**
 * A heading over a list of things names the things, not one of them.
 *
 * The shipped templates called every collection by its singular: the nav read
 * "Cliente | Oportunidad | Actividad", and a KPI counting five customers was
 * headed "CLIENTE". Singular belongs to one record — the detail page, the "add"
 * button, the belongs-to picker — and nowhere else.
 */
it('names collections in the plural across every shipped template', function () {
    $singularOnly = [];

    foreach (glob(dirname(__DIR__, 4).'/resources/app-templates/*.json') as $file) {
        $manifest = json_decode((string) file_get_contents($file), true)['manifest'];
        $name = basename($file);

        $walk = function (array $blocks) use (&$walk): array {
            $out = [];
            foreach ($blocks as $block) {
                $out[] = $block;
                foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                    $out = [...$out, ...$walk($block[$key] ?? [])];
                }
                foreach (['tabs', 'sections'] as $key) {
                    foreach ($block[$key] ?? [] as $child) {
                        $out = [...$out, ...$walk($child['blocks'] ?? [])];
                    }
                }
            }

            return $out;
        };

        // An object holds many records, so its name is the plural — every
        // heading, nav entry and count label is derived from it.
        foreach ($manifest['objects'] as $object) {
            $plural = Inflector::plural($object['name'], 'es');
            if ($object['name'] !== $plural) {
                $singularOnly[] = "{$name}: object \"{$object['name']}\" should be \"{$plural}\"";
            }

            // A one_to_many field holds many of them too; a many_to_one holds
            // exactly one and stays singular.
            foreach ($object['fields'] as $field) {
                if (($field['cardinality'] ?? null) !== 'one_to_many') {
                    continue;
                }
                $target = collect($manifest['objects'])->firstWhere('id', $field['target_object_id']);
                if ($target !== null && $field['name'] !== $target['name']) {
                    $singularOnly[] = "{$name}: field \"{$field['name']}\" holds many {$target['name']}";
                }
            }
        }

        $objectNames = collect($manifest['objects'])->pluck('name')->all();
        foreach ($manifest['pages'] as $page) {
            $blocks = $walk($page['blocks'] ?? []);
            // A detail page is about one record and keeps the singular.
            if (collect($blocks)->contains(fn (array $b): bool => ($b['type'] ?? null) === 'record_detail')) {
                continue;
            }
            foreach ($blocks as $block) {
                if (($block['type'] ?? null) !== 'metric_grid') {
                    continue;
                }
                foreach ($block['items'] ?? [] as $item) {
                    if (($item['aggregation'] ?? null) !== 'count') {
                        continue;
                    }
                    if (! in_array($item['label'], $objectNames, true)) {
                        $singularOnly[] = "{$name}: KPI \"{$item['label']}\" counts records but is not the object's name";
                    }
                }
            }
        }
    }

    expect($singularOnly)->toBeEmpty(implode('; ', $singularOnly));
});
