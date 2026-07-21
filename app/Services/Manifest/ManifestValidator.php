<?php

namespace App\Services\Manifest;

use App\Services\Records\RecordQueryService;
use App\Services\Records\SafeExpressionEvaluator;
use App\Support\Css\ScopedAppCss;
use Cron\CronExpression;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator as OpisValidator;
use Symfony\Component\ExpressionLanguage\Lexer;

/**
 * Validates an App manifest against the JSON Schema (resources/schemas/app-manifest/v1.json)
 * and then runs cross-cutting semantic rules that JSON Schema cannot express:
 * resolved references, unique slugs in scope, compatible field types per block,
 * coherent relation cardinality, well-formed expressions.
 */
class ManifestValidator
{
    private const SCHEMA_URI = 'https://sapiensly.com/schemas/app-manifest/v1.json';

    /**
     * Repo-root-relative path to the schema. It lives under resources/ (immutable
     * code that ships with every release), NOT storage/ — on hosts where storage/
     * is a shared/persistent volume that survives deploys, a storage/ copy would
     * serve a stale schema while the code is current.
     */
    private const SCHEMA_PATH = 'resources/schemas/app-manifest/v1.json';

    /** Upper bound on schema errors collected per validation (a runaway guard). */
    private const MAX_SCHEMA_ERRORS = 100;

    private ?OpisValidator $opis = null;

    /** The maxErrors=1 confirmation validator — see validateSchema(). */
    private ?OpisValidator $strictOpis = null;

    private ?Lexer $lexer = null;

    /**
     * Field-id → field map, per object id, for the manifest currently being
     * validated. Populated at the top of validate(); read by the filter
     * `related` branch to resolve a relation's target-object fields (its
     * sub-condition is scoped to the RELATED object, not the queried one). Reset
     * every validate() call.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $fieldsByObjectId = [];

    public function __construct(private ?string $schemaPath = null) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function validate(array $manifest): ManifestValidationResult
    {
        $schemaErrors = $this->validateSchema($manifest);

        // If the manifest doesn't even match the schema shape, cross-cutting rules
        // would likely throw on missing keys. Bail early with the schema errors.
        if ($schemaErrors !== []) {
            return ManifestValidationResult::fail($schemaErrors);
        }

        $warnings = $this->collectWarnings($manifest);
        $semanticErrors = $this->validateCrossCutting($manifest);

        if ($semanticErrors !== []) {
            return ManifestValidationResult::fail($semanticErrors, $warnings);
        }

        return ManifestValidationResult::ok($warnings);
    }

    /**
     * Non-blocking completeness checks: surface controls that are structurally
     * valid but DO NOTHING, so the builder AI gets a signal that it left a task
     * half-wired instead of assuming success. These never fail validation.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<ManifestValidationError>
     */
    private function collectWarnings(array $manifest): array
    {
        $warnings = [];
        foreach ($manifest['pages'] ?? [] as $pi => $page) {
            $this->collectBlockWarnings($page['blocks'] ?? [], "/pages/{$pi}/blocks", $warnings);
        }

        $this->lintDesign($manifest, $warnings);
        $this->lintUnusedObjects($manifest, $warnings);

        return $warnings;
    }

    /**
     * Flag an object that no block references. On a dashboard this is almost always
     * a mistake: a connected object was INGESTED (a live external read set up, a
     * `tickets_by_dimension` sampled from YuhuGo) and then never charted — the data
     * costs a round-trip on every load and answers nothing. A real board shipped three
     * such orphans. It is a warning, not an error: an object staged for a page still
     * being built is legitimate.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<ManifestValidationError>  $warnings
     */
    private function lintUnusedObjects(array $manifest, array &$warnings): void
    {
        $objects = $manifest['objects'] ?? [];
        if ($objects === []) {
            return;
        }

        $referenced = [];
        $this->collectObjectIdRefs($manifest['pages'] ?? [], $referenced);

        foreach ($objects as $i => $object) {
            $id = $object['id'] ?? null;
            if ($id === null || isset($referenced[$id])) {
                continue;
            }
            $connected = ($object['source']['type'] ?? 'internal') === 'connected';
            $name = $object['name'] ?? $object['slug'] ?? $id;
            $warnings[] = new ManifestValidationError(
                "/objects/{$i}",
                $connected
                    ? "Connected object '{$name}' is read live from its integration but NO block references it — every page load pays that external round-trip for nothing. Chart it (a block whose data_source.object_id is '{$id}'), or drop the object."
                    : "Object '{$name}' has no block referencing it — it will never appear on any page. Add a block that reads it, or drop the object.",
                'unused_object',
            );
        }
    }

    /**
     * Recursively collect every `object_id` value anywhere under $node (block
     * data_source/query, spark, ratio_denominator, hero.stat, metric_grid items,
     * funnel stages, insight compute, per-series object_id, nested tabs/sections).
     * A blanket key-scan catches all reference sites without enumerating them.
     *
     * @param  array<string, true>  $found
     */
    private function collectObjectIdRefs(mixed $node, array &$found): void
    {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if ($key === 'object_id' && is_string($value)) {
                $found[$value] = true;
            } elseif (is_array($value)) {
                $this->collectObjectIdRefs($value, $found);
            }
        }
    }

    /**
     * Design-lint: high-precision, NON-blocking nudges about pages that will look
     * broken or empty — caught statically, before anything renders.
     *  R1 stub page: top-level blocks are all structural chrome (no content/function).
     *  R3 orphan param block: a block needs {{params.X}} but nothing provides X
     *     (no inbound navigation carries it, no filter_bar on the page, no
     *     visibility guard) — the "renders empty / No record selected" smell.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<ManifestValidationError>  $warnings
     */
    private function lintDesign(array $manifest, array &$warnings): void
    {
        $inbound = $this->inboundNavParams($manifest);

        foreach ($manifest['pages'] ?? [] as $pi => $page) {
            $blocks = $page['blocks'] ?? [];

            // R1 — a page whose every top-level block is structural chrome.
            $structural = ['heading', 'spacer', 'divider', 'breadcrumb'];
            $nonStructural = array_filter($blocks, fn (array $b): bool => ! in_array($b['type'] ?? '', $structural, true));
            if ($blocks === [] || $nonStructural === []) {
                $warnings[] = new ManifestValidationError(
                    "/pages/{$pi}",
                    "page '".($page['slug'] ?? $pi)."' has no real content — only headings/spacers. Add a table, form, chart or other functional block, or remove the page.",
                    'design_smell',
                );
            }

            // R3 — param-dependent blocks with no source for the param.
            $provided = $inbound[$page['path'] ?? ''] ?? [];
            foreach ($this->filterBarParams($blocks) as $p) {
                $provided[$p] = true;
            }
            $this->lintParamBlocks($blocks, "/pages/{$pi}/blocks", $provided, $warnings);
        }
    }

    /**
     * Map each page path to the set of query params that some in-manifest
     * `navigate` action routes INTO it with (e.g. /comanda?id=… provides `id`).
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, bool>>
     */
    private function inboundNavParams(array $manifest): array
    {
        $map = [];
        $walk = function (mixed $node) use (&$walk, &$map): void {
            if (! is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'navigate' && is_string($node['to'] ?? null)) {
                [$path, $query] = array_pad(explode('?', $node['to'], 2), 2, '');
                if ($query !== '' && preg_match_all('/([a-z][a-z0-9_]*)=/i', $query, $m)) {
                    foreach ($m[1] as $param) {
                        $map[$path][$param] = true;
                    }
                }
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($manifest['pages'] ?? []);

        return $map;
    }

    /**
     * Param names a page's filter_bar controls write (they satisfy a dependency).
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    private function filterBarParams(array $blocks): array
    {
        $params = [];
        $walk = function (mixed $node) use (&$walk, &$params): void {
            if (! is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'filter_bar') {
                foreach ($node['controls'] ?? [] as $control) {
                    if (is_string($control['param'] ?? null)) {
                        $params[] = $control['param'];
                    }
                }
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($blocks);

        return $params;
    }

    /**
     * Walk blocks; warn when one reads {{params.X}} (record_detail/related_list
     * record/parent id, or a data_source filter) for an X that isn't provided and
     * the block has no visibility guard.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, bool>  $provided
     * @param  list<ManifestValidationError>  $warnings
     */
    private function lintParamBlocks(array $blocks, string $path, array $provided, array &$warnings): void
    {
        foreach ($blocks as $i => $block) {
            $bp = "{$path}/{$i}";
            $hasGuard = isset($block['visibility']['expression']);

            if (! $hasGuard) {
                $exprs = [
                    $block['record_id_expression'] ?? null,
                    $block['parent_id_expression'] ?? null,
                ];
                foreach ($this->filterValueExpressions($block['data_source']['filter'] ?? null) as $e) {
                    $exprs[] = $e;
                }
                foreach (array_filter($exprs, 'is_string') as $expr) {
                    foreach ($this->paramRefs($expr) as $param) {
                        if (! isset($provided[$param])) {
                            $warnings[] = new ManifestValidationError(
                                $bp,
                                "this {$block['type']} depends on {{params.{$param}}}, but nothing provides it (no inbound link carries it, no filter_bar, no visibility guard) — it will render empty. Set it via a filter_bar / a link into this page, or guard the block with a visibility expression.",
                                'design_smell',
                            );
                            break 2;
                        }
                    }
                }
            }

            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (! empty($block[$key])) {
                    $this->lintParamBlocks($block[$key], "{$bp}/{$key}", $provided, $warnings);
                }
            }
            foreach ($block['tabs'] ?? [] as $ti => $tab) {
                $this->lintParamBlocks($tab['blocks'] ?? [], "{$bp}/tabs/{$ti}/blocks", $provided, $warnings);
            }
            foreach ($block['sections'] ?? [] as $si => $section) {
                $this->lintParamBlocks($section['blocks'] ?? [], "{$bp}/sections/{$si}/blocks", $provided, $warnings);
            }
        }
    }

    /**
     * Collect the value_expression strings inside a filter tree.
     *
     * @param  array<string, mixed>|null  $filter
     * @return list<string>
     */
    private function filterValueExpressions(?array $filter): array
    {
        if ($filter === null) {
            return [];
        }
        $out = [];
        if (isset($filter['value_expression']) && is_string($filter['value_expression'])) {
            $out[] = $filter['value_expression'];
        }
        foreach ($filter['conditions'] ?? [] as $cond) {
            if (is_array($cond)) {
                $out = array_merge($out, $this->filterValueExpressions($cond));
            }
        }
        if (isset($filter['condition']) && is_array($filter['condition'])) {
            $out = array_merge($out, $this->filterValueExpressions($filter['condition']));
        }

        return $out;
    }

    /**
     * Param names referenced as {{params.X}} (incl. inside `not params.x`, ternaries).
     *
     * @return list<string>
     */
    private function paramRefs(string $expr): array
    {
        if (preg_match_all('/params\.([a-z][a-z0-9_]*)/i', $expr, $m)) {
            return array_values(array_unique($m[1]));
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<ManifestValidationError>  $warnings
     */
    private function collectBlockWarnings(array $blocks, string $path, array &$warnings): void
    {
        foreach ($blocks as $i => $block) {
            $bp = "{$path}/{$i}";
            $type = $block['type'] ?? '';

            if (in_array($type, ['form', 'multi_step_form'], true)
                && ! $this->sequenceHasEffect($block['on_submit'] ?? [], requirePersist: true)) {
                $warnings[] = new ManifestValidationError(
                    "{$bp}/on_submit",
                    "this {$type} collects input but its on_submit never persists or acts on it (no create_record / update_record / run_workflow) — the submit button looks functional but does nothing. Wire the action, or if the task needs logic the platform can't express, tell the user what's missing instead of leaving it empty.",
                    'incomplete_action',
                );
            }

            if ($type === 'button'
                && ! $this->sequenceHasEffect($block['on_click'] ?? [], requirePersist: false)) {
                $warnings[] = new ManifestValidationError(
                    "{$bp}/on_click",
                    'this button has no effect — its on_click only shows a toast or refreshes, it never changes data or navigates.',
                    'no_effect',
                );
            }

            if ($type === 'table') {
                foreach ($block['columns'] ?? [] as $ci => $col) {
                    if (($col['type'] ?? '') === 'action'
                        && ! $this->sequenceHasEffect($col['on_click'] ?? [], requirePersist: false)) {
                        $warnings[] = new ManifestValidationError(
                            "{$bp}/columns/{$ci}/on_click",
                            'this action column has no effect — its on_click only shows a toast or refreshes.',
                            'no_effect',
                        );
                    }
                }
            }

            // Design-lint R2: a tappable product grid with no image reads as bare.
            if ($type === 'card_grid'
                && ($block['on_click'] ?? []) !== []
                && empty($block['image_field_id'])) {
                $warnings[] = new ManifestValidationError(
                    "{$bp}/image_field_id",
                    'this card_grid is tappable but has no image_field_id — picker cards look bare without a thumbnail. Add an image (a string field with a URL) to the cards.',
                    'design_smell',
                );
            }

            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (! empty($block[$key])) {
                    $this->collectBlockWarnings($block[$key], "{$bp}/{$key}", $warnings);
                }
            }
            foreach ($block['tabs'] ?? [] as $ti => $tab) {
                $this->collectBlockWarnings($tab['blocks'] ?? [], "{$bp}/tabs/{$ti}/blocks", $warnings);
            }
            foreach ($block['sections'] ?? [] as $si => $section) {
                $this->collectBlockWarnings($section['blocks'] ?? [], "{$bp}/sections/{$si}/blocks", $warnings);
            }
        }
    }

    /**
     * Does an action sequence actually do something? For forms we require a
     * data change or workflow run; for buttons, navigation/modal counts too.
     *
     * @param  list<array<string, mixed>>  $actions
     */
    private function sequenceHasEffect(array $actions, bool $requirePersist): bool
    {
        $effectful = $requirePersist
            ? ['create_record', 'update_record', 'delete_record', 'run_workflow']
            : ['create_record', 'update_record', 'delete_record', 'run_workflow', 'navigate', 'open_modal', 'close_modal'];

        foreach ($actions as $action) {
            if (in_array($action['type'] ?? '', $effectful, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return ManifestValidationError[]
     */
    private function validateSchema(array $manifest): array
    {
        $result = $this->opis()->validate(
            $this->toJsonObject($manifest),
            self::SCHEMA_URI,
        );

        if ($result->isValid()) {
            return [];
        }

        // CONFIRMATION PASS — dodge a known Opis quirk: with maxErrors > 1 the
        // `block` oneOf can misjudge a VALID block as invalid depending on which
        // sibling defs exist (observed: adding block_lead_form made a valid
        // modal-nested form fail with an error belonging to another branch).
        // maxErrors=1 is Opis's battle-tested default path, so when the
        // multi-error pass reports failures but the strict pass says the
        // manifest is valid, trust the strict pass. Costs one extra validation
        // only on the (rare) invalid path.
        if ($this->strictOpis()->validate($this->toJsonObject($manifest), self::SCHEMA_URI)->isValid()) {
            return [];
        }

        $rootError = $result->error();

        $formatter = new ErrorFormatter;

        // Machine-readable expectation + offending value per data path, so each
        // schema error carries {expected, value} the model can diff — not just prose.
        $argsByPath = [];
        $this->collectLeafArgs($rootError, $argsByPath);

        // Walk the error tree emitting one error per failing leaf, but PRUNE
        // discriminated oneOf/anyOf nodes (step / block / field_definition /
        // action — each branch pinned by a `type` const): keep only the branch
        // whose `type` matched the data. Without this, a single deep mistake
        // (e.g. a too-short step id inside a `branch`) drags in every sibling
        // branch's "required property missing" / "additionalProperties" noise —
        // 40+ errors burying the real one, plus a spurious root-level hint.
        $errors = [];
        $seen = [];
        $this->collectSchemaErrors($rootError, $formatter, $argsByPath, $errors, $seen);

        return $errors;
    }

    /**
     * Recursively collect leaf schema errors, pruning the losing branches of a
     * `type`-discriminated oneOf/anyOf. When no branch matched the data's `type`
     * (an unknown/invented type), the composite node's own description is the
     * single actionable hint, so emit that instead of every branch's noise.
     *
     * @param  array<string, array{expected?: mixed, value?: mixed}>  $argsByPath
     * @param  list<ManifestValidationError>  $errors
     * @param  array<string, true>  $seen
     */
    private function collectSchemaErrors(
        ValidationError $error,
        ErrorFormatter $formatter,
        array $argsByPath,
        array &$errors,
        array &$seen,
    ): void {
        $subErrors = $error->subErrors();

        if (in_array($error->keyword(), ['oneOf', 'anyOf'], true)) {
            $matched = array_values(array_filter(
                $subErrors,
                fn (ValidationError $branch): bool => ! $this->branchRejectedByType($branch),
            ));

            // Every branch rejected on `type` → unknown/invented type. The node's
            // own description ("A workflow step. Its `type` must be one of …") is
            // the one useful signal; the per-branch noise is not.
            if ($matched === []) {
                if (($description = $this->schemaDescription($error)) !== null) {
                    $this->pushSchemaError($error, $description, $argsByPath, $errors, $seen);

                    return;
                }
                // No description to lean on: fall back to walking every branch.
                $matched = $subErrors;
            }

            // Recurse only into the surviving discriminator branch(es). When
            // nothing was pruned (a oneOf with no `type` discriminator) this is
            // simply all of them, preserving the previous behaviour.
            foreach ($matched as $branch) {
                $this->collectSchemaErrors($branch, $formatter, $argsByPath, $errors, $seen);
            }

            return;
        }

        if ($subErrors === []) {
            $this->pushSchemaError(
                $error,
                $this->describeSchemaError($formatter, $error),
                $argsByPath,
                $errors,
                $seen,
            );

            return;
        }

        // When a descendant of an object fails, Opis ALSO reports a spurious
        // `additionalProperties` error on that object listing ALL its (valid)
        // keys — the cascade that, on a deep mistake, repeats "Additional object
        // properties are not allowed: …" at every ancestor up to the root. The
        // real failure is the deeper sibling, so drop the additionalProperties
        // leaf whenever the node has other sub-errors. A genuine unknown key is
        // the node's ONLY sub-error, so it is preserved.
        $hasOtherFailure = count($subErrors) > 1;
        foreach ($subErrors as $subError) {
            if ($hasOtherFailure
                && $subError->keyword() === 'additionalProperties'
                && $subError->subErrors() === []) {
                continue;
            }
            $this->collectSchemaErrors($subError, $formatter, $argsByPath, $errors, $seen);
        }
    }

    /**
     * Append a schema error at the given node's data path, deduped by path+message.
     *
     * @param  array<string, array{expected?: mixed, value?: mixed}>  $argsByPath
     * @param  list<ManifestValidationError>  $errors
     * @param  array<string, true>  $seen
     */
    private function pushSchemaError(
        ValidationError $error,
        string $message,
        array $argsByPath,
        array &$errors,
        array &$seen,
    ): void {
        if (count($errors) >= self::MAX_SCHEMA_ERRORS) {
            return;
        }

        $path = '/'.implode('/', $error->data()->fullPath());
        $normPath = $path === '/' ? '/' : rtrim($path, '/');

        $key = $normPath.'|'.$message;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;

        $errors[] = new ManifestValidationError(
            path: $normPath,
            message: $message,
            code: 'schema',
            expected: $argsByPath[$normPath]['expected'] ?? null,
            value: $argsByPath[$normPath]['value'] ?? null,
        );
    }

    /**
     * Did this oneOf/anyOf branch fail purely because its OWN `type` const did
     * not match the data? Such a branch is a losing discriminator alternative,
     * not the author's intended shape, so its sub-errors are noise. The const
     * check sits under the branch's allOf wrapper, so we recurse — but only the
     * branch's own `type` (at its data path + "type") counts: a `type` const
     * failure from a NESTED discriminator (e.g. a losing action branch inside a
     * button block) lives deeper and must not condemn the outer branch.
     */
    private function branchRejectedByType(ValidationError $branch): bool
    {
        return $this->hasTypeConstFailureAtDepth(
            $branch,
            count($branch->data()->fullPath()) + 1,
        );
    }

    private function hasTypeConstFailureAtDepth(ValidationError $error, int $depth): bool
    {
        if ($error->keyword() === 'const') {
            $path = $error->data()->fullPath();
            if (count($path) === $depth && end($path) === 'type') {
                return true;
            }
        }

        foreach ($error->subErrors() as $subError) {
            if ($this->hasTypeConstFailureAtDepth($subError, $depth)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk the error tree and record, per leaf error's data path, the structured
     * expected value (allowed enum values, required type, pattern) and the
     * offending scalar value. Leaves only — composite (oneOf/anyOf) nodes are
     * covered separately by collectCompositeHints().
     *
     * @param  array<string, array{expected?: mixed, value?: mixed}>  $map
     */
    private function collectLeafArgs(ValidationError $error, array &$map): void
    {
        $subErrors = $error->subErrors();
        if ($subErrors === []) {
            $path = '/'.implode('/', $error->data()->fullPath());
            $path = $path === '/' ? '/' : rtrim($path, '/');

            $entry = [];
            $expected = $this->expectedFor($error);
            if ($expected !== null) {
                $entry['expected'] = $expected;
            }
            $value = $this->actualValueFor($error);
            if ($value !== null) {
                $entry['value'] = $value;
            }
            if ($entry !== [] && ! isset($map[$path])) {
                $map[$path] = $entry;
            }

            return;
        }

        foreach ($subErrors as $subError) {
            $this->collectLeafArgs($subError, $map);
        }
    }

    /**
     * The structured expectation for a leaf schema error: the keyword's own
     * `expected` arg (type/const), the allowed `enum` values from the schema node,
     * or the required `pattern`. Null when the keyword carries nothing useful.
     */
    private function expectedFor(ValidationError $error): mixed
    {
        $args = $error->args();
        if (array_key_exists('expected', $args)) {
            return $args['expected'];
        }

        if ($error->keyword() === 'enum') {
            $node = $error->schema()->info()->data();
            $enum = is_object($node) ? ($node->enum ?? null) : null;

            return is_array($enum) ? $enum : null;
        }

        if ($error->keyword() === 'pattern' && array_key_exists('pattern', $args)) {
            return $args['pattern'];
        }

        return null;
    }

    /**
     * The offending value at the error's data path, but only when it's a scalar
     * (strings capped) — dumping a whole nested object/array back at the model is
     * noise, and the path already locates it.
     */
    private function actualValueFor(ValidationError $error): mixed
    {
        $value = $error->data()->value();

        if (is_string($value)) {
            return mb_strlen($value) > 120 ? mb_substr($value, 0, 120).'…' : $value;
        }

        return is_scalar($value) ? $value : null;
    }

    /**
     * Append the failing schema node's own `description` to the default Opis
     * message, so a validation error doubles as authoring guidance the model
     * can act on without a second round-trip (e.g. an invalid enum/pattern/
     * additionalProperties returns the field's hint from the schema). Falls
     * back to the plain message when the node has no description.
     */
    private function describeSchemaError(ErrorFormatter $formatter, ValidationError $error): string
    {
        $message = $formatter->formatErrorMessage($error);
        $description = $this->schemaDescription($error);

        return $description !== null
            ? "{$message} — hint: {$description}"
            : $message;
    }

    /**
     * The failing schema node's own `description`, if any.
     */
    private function schemaDescription(ValidationError $error): ?string
    {
        $node = $error->schema()->info()->data();
        $description = is_object($node) ? ($node->description ?? null) : null;

        return is_string($description) && $description !== '' ? $description : null;
    }

    /**
     * The generic marketing-block cluster a landing must not use — these render
     * the same everywhere and read as AI-generated design (a landing composes
     * bespoke `html` sections styled in custom_css instead).
     */
    private const GENERIC_MARKETING_BLOCKS = ['hero', 'feature_grid', 'cta', 'testimonials', 'pricing', 'faq', 'stat_band'];

    /**
     * @param  array<int, mixed>  $blocks
     * @param  ManifestValidationError[]  $errors
     */
    private function rejectGenericLandingBlocks(array $blocks, string $path, array &$errors): void
    {
        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;
            if (in_array($type, self::GENERIC_MARKETING_BLOCKS, true)) {
                $errors[] = new ManifestValidationError(
                    "{$path}/{$i}",
                    "Block type '{$type}' is not allowed on a landing surface — the generic marketing blocks read as templated design. Compose the section as an `html` block with your own semantic markup and style it in settings.custom_css (see framework_reference topic 'landings').",
                    'generic_block_on_landing',
                );
            }
            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    $this->rejectGenericLandingBlocks($block[$key], "{$path}/{$i}/{$key}", $errors);
                }
            }
            foreach (['tabs', 'sections'] as $key) {
                foreach ($block[$key] ?? [] as $j => $sub) {
                    if (is_array($sub) && isset($sub['blocks']) && is_array($sub['blocks'])) {
                        $this->rejectGenericLandingBlocks($sub['blocks'], "{$path}/{$i}/{$key}/{$j}/blocks", $errors);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return ManifestValidationError[]
     */
    private function validateCrossCutting(array $manifest): array
    {
        $errors = [];

        $objects = $manifest['objects'] ?? [];
        $pages = $manifest['pages'] ?? [];
        $permissions = $manifest['permissions'] ?? [];
        $navigation = $manifest['navigation'] ?? null;
        $workflows = $manifest['workflows'] ?? [];

        // settings.custom_css is a scoped escape hatch; reject the constructs that
        // would break the <style> sandbox or the per-app isolation, at save time.
        // The length budget is surface-aware: a landing's bespoke look IS its CSS,
        // so it gets the full 60k schema ceiling; every other surface keeps 20k.
        $cssBudget = ($manifest['settings']['surface'] ?? null) === 'landing'
            ? ScopedAppCss::LANDING_MAX_LENGTH
            : ScopedAppCss::MAX_LENGTH;
        foreach (ScopedAppCss::issues($manifest['settings']['custom_css'] ?? null, $cssBudget) as $issue) {
            $errors[] = new ManifestValidationError('/settings/custom_css', $issue, 'unsafe_css');
        }

        // A landing surface is bespoke-designed (rule 1d-land): the generic
        // marketing blocks are exactly the AI-cluster look the design gate
        // exists to reject, so they fail at SAVE time — the author gets the
        // correction while composing instead of burning a critique round
        // discovering it (observed live: a whole generic v1, then a rewrite).
        if (($manifest['settings']['surface'] ?? null) === 'landing') {
            foreach ($pages as $i => $page) {
                $this->rejectGenericLandingBlocks($page['blocks'] ?? [], "/pages/{$i}/blocks", $errors);
            }
        }

        $objectsById = [];
        $objectsBySlug = [];
        foreach ($objects as $i => $object) {
            if (isset($objectsById[$object['id']])) {
                $errors[] = new ManifestValidationError(
                    "/objects/{$i}/id",
                    "Duplicate object id '{$object['id']}'",
                    'duplicate_id',
                );
            }
            if (isset($objectsBySlug[$object['slug']])) {
                $errors[] = new ManifestValidationError(
                    "/objects/{$i}/slug",
                    "Duplicate object slug '{$object['slug']}'",
                    'duplicate_slug',
                );
            }
            $objectsById[$object['id']] = $object;
            $objectsBySlug[$object['slug']] = $object;
        }

        // A complete field-id → field map per object, available up-front so the
        // recursive filter validator can resolve a `related` filter's sub-
        // condition against the RELATED object's fields (not the queried one).
        $this->fieldsByObjectId = [];
        foreach ($objects as $object) {
            $map = [];
            foreach ($object['fields'] ?? [] as $field) {
                if (isset($field['id'])) {
                    $map[$field['id']] = $field;
                }
            }
            $this->fieldsByObjectId[$object['id'] ?? ''] = $map;
        }

        $fieldsByObjectId = [];
        foreach ($objects as $i => $object) {
            $fieldsById = [];
            $fieldsBySlug = [];
            foreach ($object['fields'] as $j => $field) {
                if (isset($fieldsById[$field['id']])) {
                    $errors[] = new ManifestValidationError(
                        "/objects/{$i}/fields/{$j}/id",
                        "Duplicate field id '{$field['id']}' in object '{$object['slug']}'",
                        'duplicate_id',
                    );
                }
                if (isset($fieldsBySlug[$field['slug']])) {
                    $errors[] = new ManifestValidationError(
                        "/objects/{$i}/fields/{$j}/slug",
                        "Duplicate field slug '{$field['slug']}' in object '{$object['slug']}'",
                        'duplicate_slug',
                    );
                }
                $fieldsById[$field['id']] = $field;
                $fieldsBySlug[$field['slug']] = $field;
            }
            $fieldsByObjectId[$object['id']] = $fieldsById;

            if (isset($object['primary_display_field_id']) && ! isset($fieldsById[$object['primary_display_field_id']])) {
                $errors[] = new ManifestValidationError(
                    "/objects/{$i}/primary_display_field_id",
                    "primary_display_field_id '{$object['primary_display_field_id']}' does not match any field in object '{$object['slug']}'",
                    'unresolved_ref',
                );
            }

            foreach ($object['fields'] as $j => $field) {
                if ($field['type'] === 'relation') {
                    if (! isset($objectsById[$field['target_object_id']])) {
                        $errors[] = new ManifestValidationError(
                            "/objects/{$i}/fields/{$j}/target_object_id",
                            "Relation field '{$field['slug']}' targets unknown object id '{$field['target_object_id']}'",
                            'unresolved_ref',
                        );
                    }
                }

                // rating: default (if set) must fit in [0, max].
                if ($field['type'] === 'rating' && isset($field['default'])) {
                    $max = $field['max'] ?? 5;
                    if ($field['default'] < 0 || $field['default'] > $max) {
                        $errors[] = new ManifestValidationError(
                            "/objects/{$i}/fields/{$j}/default",
                            "rating default {$field['default']} must be between 0 and {$max}",
                            'incompatible_value',
                        );
                    }
                }

                // slider: min < max, default within range, currency format
                // requires a currency_code.
                if ($field['type'] === 'slider') {
                    $min = $field['min'] ?? 0;
                    $max = $field['max'] ?? 100;
                    if ($min >= $max) {
                        $errors[] = new ManifestValidationError(
                            "/objects/{$i}/fields/{$j}",
                            "slider min ({$min}) must be strictly less than max ({$max})",
                            'incompatible_value',
                        );
                    }
                    if (isset($field['default']) && ($field['default'] < $min || $field['default'] > $max)) {
                        $errors[] = new ManifestValidationError(
                            "/objects/{$i}/fields/{$j}/default",
                            "slider default {$field['default']} must be between {$min} and {$max}",
                            'incompatible_value',
                        );
                    }
                    if (($field['format'] ?? null) === 'currency' && ! isset($field['currency_code'])) {
                        $errors[] = new ManifestValidationError(
                            "/objects/{$i}/fields/{$j}/currency_code",
                            'slider with format=currency requires currency_code',
                            'missing_required',
                        );
                    }
                }

                // date_range: default.from <= default.to when both present.
                if ($field['type'] === 'date_range' && isset($field['default']['from'], $field['default']['to'])) {
                    if (strcmp((string) $field['default']['from'], (string) $field['default']['to']) > 0) {
                        $errors[] = new ManifestValidationError(
                            "/objects/{$i}/fields/{$j}/default",
                            "date_range default 'from' must be <= 'to'",
                            'incompatible_value',
                        );
                    }
                }
            }
        }

        // Second pass over fields — validates derived fields (lookup/rollup)
        // that reference fields on OTHER objects. Done after $fieldsByObjectId
        // is fully built so forward references resolve.
        foreach ($objects as $i => $object) {
            foreach ($object['fields'] as $j => $field) {
                if (! in_array($field['type'], ['lookup', 'rollup'], true)) {
                    continue;
                }

                $viaFieldId = $field['via_relation_field_id'] ?? null;
                $viaField = $fieldsByObjectId[$object['id']][$viaFieldId] ?? null;

                if ($viaField === null) {
                    $errors[] = new ManifestValidationError(
                        "/objects/{$i}/fields/{$j}/via_relation_field_id",
                        "{$field['type']} field references unknown via_relation_field_id '{$viaFieldId}'",
                        'unresolved_ref',
                    );

                    continue;
                }

                if ($viaField['type'] !== 'relation') {
                    $errors[] = new ManifestValidationError(
                        "/objects/{$i}/fields/{$j}/via_relation_field_id",
                        "{$field['type']} field must reference a relation field, not '{$viaField['type']}'",
                        'incompatible_type',
                    );

                    continue;
                }

                $targetFieldId = $field['target_field_id'] ?? null;
                $needsTarget = $field['type'] === 'lookup'
                    || ! in_array($field['aggregator'] ?? '', ['count', 'count_distinct'], true);

                if (! $needsTarget) {
                    continue;
                }

                $targetObjectFields = $fieldsByObjectId[$viaField['target_object_id']] ?? [];
                if ($targetFieldId === null || ! isset($targetObjectFields[$targetFieldId])) {
                    $errors[] = new ManifestValidationError(
                        "/objects/{$i}/fields/{$j}/target_field_id",
                        "{$field['type']} field target_field_id '{$targetFieldId}' does not belong to the related object",
                        'unresolved_ref',
                    );

                    continue;
                }

                $numericTypes = ['number', 'currency', 'rating', 'slider'];
                if ($field['type'] === 'rollup'
                    && in_array($field['aggregator'] ?? '', ['sum', 'avg', 'min', 'max'], true)
                    && ! in_array($targetObjectFields[$targetFieldId]['type'], $numericTypes, true)
                    && ! $this->derivedSatisfiesNumeric($targetObjectFields[$targetFieldId], $numericTypes)) {
                    $errors[] = new ManifestValidationError(
                        "/objects/{$i}/fields/{$j}/target_field_id",
                        "rollup aggregator '{$field['aggregator']}' requires a numeric target field, got '{$targetObjectFields[$targetFieldId]['type']}'",
                        'incompatible_type',
                    );
                }
            }
        }

        // Detect formula cycles. We build a dependency graph from each formula
        // field to other formula fields it references via {{<slug>}}, then DFS
        // for back-edges. Formulas can reference non-formula fields safely.
        foreach ($objects as $i => $object) {
            $formulasInObject = [];
            $slugToField = [];
            foreach ($object['fields'] as $field) {
                $slugToField[$field['slug']] = $field;
                if ($field['type'] === 'formula') {
                    $formulasInObject[$field['slug']] = $field;
                }
            }
            foreach ($formulasInObject as $slug => $formula) {
                $visited = [];
                if ($this->formulaHasCycle($slug, $formulasInObject, $visited)) {
                    $errors[] = new ManifestValidationError(
                        "/objects/{$i}/fields",
                        "Formula '{$slug}' takes part in a dependency cycle",
                        'formula_cycle',
                    );
                    break; // one error per object is enough
                }
            }

            // Validate each formula's expression: well-formed braces + only
            // catalog functions inside its {{…}} tokens (same checks the action
            // dialect gets), then that every {{slug}} it references resolves to a
            // real field on this object (or a system field). Without this, a typo'd
            // function or a reference to a non-existent field passes silently and
            // only surfaces as a null value at runtime.
            $validSlugs = array_fill_keys(array_column($object['fields'], 'slug'), true);
            foreach ($object['fields'] as $j => $field) {
                if (($field['type'] ?? null) !== 'formula') {
                    continue;
                }
                $expression = (string) ($field['expression'] ?? '');
                $path = "/objects/{$i}/fields/{$j}/expression";

                $this->validateExpression($expression, $path, 'formula expression', $errors);

                foreach ($this->formulaVariableReferences($expression) as $ref) {
                    if (isset($validSlugs[$ref]) || RecordQueryService::systemField($ref) !== null) {
                        continue;
                    }
                    $errors[] = new ManifestValidationError(
                        $path,
                        "formula expression references unknown field '{$ref}' — it must be a field on this object (or a system field like sys_created_at)",
                        'unresolved_ref',
                    );
                }
            }
        }

        foreach ($objects as $i => $object) {
            foreach ($object['fields'] as $j => $field) {
                if ($field['type'] !== 'relation' || ! isset($field['inverse_field_id'])) {
                    continue;
                }
                $target = $objectsById[$field['target_object_id']] ?? null;
                if ($target === null) {
                    continue; // already reported above
                }
                $inverse = null;
                foreach ($target['fields'] as $tf) {
                    if ($tf['id'] === $field['inverse_field_id']) {
                        $inverse = $tf;
                        break;
                    }
                }
                if ($inverse === null) {
                    $errors[] = new ManifestValidationError(
                        "/objects/{$i}/fields/{$j}/inverse_field_id",
                        "inverse_field_id '{$field['inverse_field_id']}' not found in target object",
                        'unresolved_ref',
                    );

                    continue;
                }
                if (($inverse['type'] ?? null) !== 'relation') {
                    $errors[] = new ManifestValidationError(
                        "/objects/{$i}/fields/{$j}/inverse_field_id",
                        "inverse_field_id must refer to a relation field, got '{$inverse['type']}'",
                        'incompatible_type',
                    );

                    continue;
                }
                if (! $this->cardinalitiesMatch($field['cardinality'], $inverse['cardinality'] ?? '')) {
                    $errors[] = new ManifestValidationError(
                        "/objects/{$i}/fields/{$j}/cardinality",
                        "Cardinality '{$field['cardinality']}' is not the inverse of '{$inverse['cardinality']}'",
                        'incompatible_cardinality',
                    );
                }
            }
        }

        $pagesById = [];
        $pagesBySlug = [];
        $pathsSeen = [];
        foreach ($pages as $i => $page) {
            if (isset($pagesById[$page['id']])) {
                $errors[] = new ManifestValidationError(
                    "/pages/{$i}/id",
                    "Duplicate page id '{$page['id']}'",
                    'duplicate_id',
                );
            }
            if (isset($pagesBySlug[$page['slug']])) {
                $errors[] = new ManifestValidationError(
                    "/pages/{$i}/slug",
                    "Duplicate page slug '{$page['slug']}'",
                    'duplicate_slug',
                );
            }
            if (isset($pathsSeen[$page['path']])) {
                $errors[] = new ManifestValidationError(
                    "/pages/{$i}/path",
                    "Duplicate page path '{$page['path']}'",
                    'duplicate_path',
                );
            }
            $pagesById[$page['id']] = $page;
            $pagesBySlug[$page['slug']] = $page;
            $pathsSeen[$page['path']] = true;

            // First pass over the page: collect ids of any modal blocks so
            // open_modal/close_modal actions inside the same page can reference
            // them. Modals are page-scoped — referencing a modal from another
            // page is not allowed in MVP.
            $modalIdsInPage = [];
            $this->collectModalIds($page['blocks'] ?? [], $modalIdsInPage);

            $this->validateBlocks(
                $page['blocks'] ?? [],
                "/pages/{$i}/blocks",
                $objectsById,
                $fieldsByObjectId,
                $modalIdsInPage,
                $errors,
            );
        }

        $roles = $permissions['roles'] ?? [];
        $rolesById = [];
        $rolesBySlug = [];
        foreach ($roles as $i => $role) {
            if (isset($rolesById[$role['id']])) {
                $errors[] = new ManifestValidationError(
                    "/permissions/roles/{$i}/id",
                    "Duplicate role id '{$role['id']}'",
                    'duplicate_id',
                );
            }
            if (isset($rolesBySlug[$role['slug']])) {
                $errors[] = new ManifestValidationError(
                    "/permissions/roles/{$i}/slug",
                    "Duplicate role slug '{$role['slug']}'",
                    'duplicate_slug',
                );
            }
            $rolesById[$role['id']] = $role;
            $rolesBySlug[$role['slug']] = $role;
        }

        // A deterministic default role is what an open-mode member falls back to.
        // More than one is ambiguous; with 2+ roles, none is too (which one wins?).
        // A single-role app needs no flag — that role is unambiguously the default.
        $defaultRoleIndexes = [];
        foreach ($roles as $i => $role) {
            if (($role['is_default'] ?? false) === true) {
                $defaultRoleIndexes[] = $i;
            }
        }
        if (count($defaultRoleIndexes) > 1) {
            foreach (array_slice($defaultRoleIndexes, 1) as $i) {
                $errors[] = new ManifestValidationError(
                    "/permissions/roles/{$i}/is_default",
                    'Only one role may be marked is_default',
                    'duplicate_default_role',
                );
            }
        } elseif ($defaultRoleIndexes === [] && count($roles) > 1) {
            $errors[] = new ManifestValidationError(
                '/permissions/roles',
                'Exactly one role must be marked is_default when more than one role is defined',
                'missing_default_role',
            );
        }

        foreach ($permissions['object_policies'] ?? [] as $i => $policy) {
            if (! isset($objectsById[$policy['object_id']])) {
                $errors[] = new ManifestValidationError(
                    "/permissions/object_policies/{$i}/object_id",
                    "object_id '{$policy['object_id']}' does not match any defined object",
                    'unresolved_ref',
                );
            }
            if (! isset($rolesById[$policy['role_id']])) {
                $errors[] = new ManifestValidationError(
                    "/permissions/object_policies/{$i}/role_id",
                    "role_id '{$policy['role_id']}' does not match any defined role",
                    'unresolved_ref',
                );
            }
            $objectFields = $fieldsByObjectId[$policy['object_id']] ?? [];
            foreach ($policy['field_restrictions']['hidden'] ?? [] as $k => $fid) {
                if (! isset($objectFields[$fid])) {
                    $errors[] = new ManifestValidationError(
                        "/permissions/object_policies/{$i}/field_restrictions/hidden/{$k}",
                        "field_id '{$fid}' does not belong to object '{$policy['object_id']}'",
                        'unresolved_ref',
                    );
                }
            }
            foreach ($policy['field_restrictions']['readonly'] ?? [] as $k => $fid) {
                if (! isset($objectFields[$fid])) {
                    $errors[] = new ManifestValidationError(
                        "/permissions/object_policies/{$i}/field_restrictions/readonly/{$k}",
                        "field_id '{$fid}' does not belong to object '{$policy['object_id']}'",
                        'unresolved_ref',
                    );
                }
            }
        }

        foreach ($permissions['page_policies'] ?? [] as $i => $policy) {
            if (! isset($pagesById[$policy['page_id']])) {
                $errors[] = new ManifestValidationError(
                    "/permissions/page_policies/{$i}/page_id",
                    "page_id '{$policy['page_id']}' does not match any defined page",
                    'unresolved_ref',
                );
            }
            if (! isset($rolesById[$policy['role_id']])) {
                $errors[] = new ManifestValidationError(
                    "/permissions/page_policies/{$i}/role_id",
                    "role_id '{$policy['role_id']}' does not match any defined role",
                    'unresolved_ref',
                );
            }
        }

        if ($navigation !== null) {
            $this->validateNavigation($navigation['items'] ?? [], '/navigation/items', $pagesById, $errors);
        }

        // Workflows: id/slug uniqueness, trigger object_id resolution, step
        // record.* object_ids, and run_workflow action targets resolved.
        $workflowsById = [];
        $workflowSlugsSeen = [];
        foreach ($workflows as $i => $workflow) {
            if (isset($workflowsById[$workflow['id']])) {
                $errors[] = new ManifestValidationError(
                    "/workflows/{$i}/id",
                    "Duplicate workflow id '{$workflow['id']}'",
                    'duplicate_id',
                );
            }
            if (isset($workflowSlugsSeen[$workflow['slug']])) {
                $errors[] = new ManifestValidationError(
                    "/workflows/{$i}/slug",
                    "Duplicate workflow slug '{$workflow['slug']}'",
                    'duplicate_slug',
                );
            }
            $workflowsById[$workflow['id']] = $workflow;
            $workflowSlugsSeen[$workflow['slug']] = true;

            // Trigger validations
            $trigger = $workflow['trigger'] ?? [];
            $triggerType = $trigger['type'] ?? null;
            if (in_array($triggerType, ['record.created', 'record.updated', 'record.deleted', 'record.date_reached'], true)) {
                $triggerObjectId = $trigger['object_id'] ?? null;
                if ($triggerObjectId === null || ! isset($objectsById[$triggerObjectId])) {
                    $errors[] = new ManifestValidationError(
                        "/workflows/{$i}/trigger/object_id",
                        "trigger references unknown object_id '{$triggerObjectId}'",
                        'unresolved_ref',
                    );
                } else {
                    $this->validateFilterExpression(
                        $trigger['filter'] ?? null,
                        "/workflows/{$i}/trigger/filter",
                        $fieldsByObjectId[$triggerObjectId] ?? [],
                        $errors,
                        forTrigger: true,
                    );

                    // date_reached must point at a date/datetime field on the object.
                    if ($triggerType === 'record.date_reached') {
                        $fieldId = $trigger['field_id'] ?? null;
                        $field = $fieldsByObjectId[$triggerObjectId][$fieldId] ?? null;
                        if ($field === null) {
                            $errors[] = new ManifestValidationError(
                                "/workflows/{$i}/trigger/field_id",
                                "date_reached trigger field_id '".($fieldId ?? '')."' does not belong to the object",
                                'unresolved_ref',
                            );
                        } elseif (! in_array($field['type'] ?? null, ['date', 'datetime'], true)) {
                            $errors[] = new ManifestValidationError(
                                "/workflows/{$i}/trigger/field_id",
                                "date_reached trigger field_id must be a date or datetime field, got '".($field['type'] ?? '')."'",
                                'incompatible_type',
                            );
                        }
                    }
                }
            }

            if ($triggerType === 'schedule' && ! CronExpression::isValidExpression((string) ($trigger['cron'] ?? ''))) {
                $errors[] = new ManifestValidationError(
                    "/workflows/{$i}/trigger/cron",
                    "trigger.cron '".($trigger['cron'] ?? '')."' is not a valid 5-field cron expression",
                    'invalid_cron',
                );
            }

            $this->validateWorkflowSteps(
                $workflow['steps'] ?? [],
                "/workflows/{$i}/steps",
                $objectsById,
                $errors,
            );

            $this->validateWorkflowExpressionContext(
                $workflow['steps'] ?? [],
                "/workflows/{$i}/steps",
                $errors,
            );
        }

        // Second pass over pages/blocks: validate run_workflow action targets
        // now that we have the workflow ids collected.
        foreach ($pages as $i => $page) {
            $this->validateRunWorkflowRefs(
                $page['blocks'] ?? [],
                "/pages/{$i}/blocks",
                $workflowsById,
                $errors,
            );
        }

        return $errors;
    }

    /**
     * Context roots that exist in the page/UI runtime but NOT inside a workflow.
     * A workflow only sees trigger, vars, steps and current_user — page values
     * must be passed in via the run_workflow action's `input` map and then read
     * as {{trigger.…}}. Referencing these inside a workflow silently resolves to
     * null at runtime, so we reject them at save with a guiding message.
     */
    private const WORKFLOW_FORBIDDEN_ROOTS = ['form', 'params', 'row'];

    /**
     * Deep-scan every expression string in a workflow's step tree and reject
     * references to roots that don't exist in the workflow context.
     *
     * @param  ManifestValidationError[]  $errors
     */
    private function validateWorkflowExpressionContext(mixed $node, string $path, array &$errors): void
    {
        if (is_string($node)) {
            $root = $this->forbiddenRootIn($node, self::WORKFLOW_FORBIDDEN_ROOTS);
            if ($root !== null) {
                $errors[] = new ManifestValidationError(
                    $path,
                    "expression references '{{{$root}.…}}', which is not available inside a workflow (workflows only see trigger, vars, steps and current_user). Pass the page value into the workflow via the run_workflow action's `input` map, then read it here as '{{trigger.…}}'.",
                    'invalid_context',
                );
            }

            return;
        }

        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if ($key === 'code') {
                    continue; // script.run JS body — not an expression
                }
                $this->validateWorkflowExpressionContext($value, "{$path}/{$key}", $errors);
            }
        }
    }

    /**
     * Returns the first forbidden context root referenced as a path base inside
     * any {{ … }} token of $value, or null. String literals are stripped so a
     * quoted "form" doesn't count, and a property access like `record.form` is
     * excluded by the lookbehind.
     *
     * @param  list<string>  $forbidden
     */
    private function forbiddenRootIn(string $value, array $forbidden): ?string
    {
        if (! str_contains($value, '{{')) {
            return null;
        }
        if (! preg_match_all('/\{\{\s*(.+?)\s*\}\}/', $value, $matches)) {
            return null;
        }

        foreach ($matches[1] as $inner) {
            $stripped = preg_replace('/([\'"]).*?\1/', '', $inner) ?? $inner;
            foreach ($forbidden as $root) {
                if (preg_match('/(?<![\w.])'.preg_quote($root, '/').'\b/', $stripped)) {
                    return $root;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, array<string, mixed>>  $objectsById
     * @param  ManifestValidationError[]  $errors
     */
    private function validateWorkflowSteps(array $steps, string $pathPrefix, array $objectsById, array &$errors): void
    {
        foreach ($steps as $i => $step) {
            $stepPath = "{$pathPrefix}/{$i}";
            $type = $step['type'] ?? null;

            if (in_array($type, ['record.create', 'record.update', 'record.delete', 'record.query', 'record.aggregate'], true)) {
                $objectId = $step['object_id'] ?? null;
                if ($objectId === null || ! isset($objectsById[$objectId])) {
                    $errors[] = new ManifestValidationError(
                        "{$stepPath}/object_id",
                        "step '{$type}' references unknown object_id '{$objectId}'",
                        'unresolved_ref',
                    );
                }
            }

            if ($type === 'record.aggregate') {
                $aggregation = $step['aggregation'] ?? null;
                $fieldId = $step['field_id'] ?? null;

                // Every aggregation except count reduces over a FIELD's values.
                if ($aggregation !== 'count' && ($fieldId === null || $fieldId === '')) {
                    $errors[] = new ManifestValidationError(
                        "{$stepPath}/field_id",
                        "step 'record.aggregate' with aggregation '{$aggregation}' requires field_id",
                        'missing_ref',
                    );
                }

                // A named field_id must belong to the aggregated object.
                $objectId = $step['object_id'] ?? null;
                if ($fieldId !== null && isset($objectsById[$objectId])
                    && ! in_array($fieldId, array_column($objectsById[$objectId]['fields'] ?? [], 'id'), true)) {
                    $errors[] = new ManifestValidationError(
                        "{$stepPath}/field_id",
                        "step 'record.aggregate' references field_id '{$fieldId}' not on object '{$objectId}'",
                        'unresolved_ref',
                    );
                }
            }

            if ($type === 'branch') {
                foreach ($step['cases'] ?? [] as $j => $case) {
                    $this->validateWorkflowSteps(
                        $case['steps'] ?? [],
                        "{$stepPath}/cases/{$j}/steps",
                        $objectsById,
                        $errors,
                    );
                }
                $this->validateWorkflowSteps(
                    $step['default_steps'] ?? [],
                    "{$stepPath}/default_steps",
                    $objectsById,
                    $errors,
                );
            }

            if ($type === 'foreach') {
                $this->validateWorkflowSteps(
                    $step['steps'] ?? [],
                    "{$stepPath}/steps",
                    $objectsById,
                    $errors,
                );
            }
        }
    }

    /**
     * Walk page block trees and validate that every run_workflow action's
     * workflow_id matches a workflow declared at the manifest root.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, array<string, mixed>>  $workflowsById
     * @param  ManifestValidationError[]  $errors
     */
    private function validateRunWorkflowRefs(array $blocks, string $pathPrefix, array $workflowsById, array &$errors): void
    {
        foreach ($blocks as $i => $block) {
            $blockPath = "{$pathPrefix}/{$i}";

            foreach (['on_click', 'on_submit', 'on_cancel'] as $key) {
                foreach ($block[$key] ?? [] as $j => $action) {
                    if (($action['type'] ?? null) === 'run_workflow') {
                        $wfId = $action['workflow_id'] ?? null;
                        if ($wfId === null || ! isset($workflowsById[$wfId])) {
                            $errors[] = new ManifestValidationError(
                                "{$blockPath}/{$key}/{$j}/workflow_id",
                                "run_workflow references unknown workflow '{$wfId}'",
                                'unresolved_ref',
                            );
                        }
                    }
                }
            }

            // Action columns inside a table block carry on_click sequences too.
            if (($block['type'] ?? null) === 'table') {
                foreach ($block['columns'] ?? [] as $colIdx => $column) {
                    if (($column['type'] ?? null) !== 'action') {
                        continue;
                    }
                    foreach ($column['on_click'] ?? [] as $aIdx => $action) {
                        if (($action['type'] ?? null) !== 'run_workflow') {
                            continue;
                        }
                        $wfId = $action['workflow_id'] ?? null;
                        if ($wfId === null || ! isset($workflowsById[$wfId])) {
                            $errors[] = new ManifestValidationError(
                                "{$blockPath}/columns/{$colIdx}/on_click/{$aIdx}/workflow_id",
                                "run_workflow references unknown workflow '{$wfId}'",
                                'unresolved_ref',
                            );
                        }
                    }
                }
            }

            // Recurse through every container shape so a run_workflow nested
            // inside a tab/accordion/split_view still gets validated.
            foreach ($this->childBlockLists($block) as $childPath => $childBlocks) {
                $this->validateRunWorkflowRefs($childBlocks, "{$blockPath}/{$childPath}", $workflowsById, $errors);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, array<string, mixed>>  $objectsById
     * @param  array<string, array<string, array<string, mixed>>>  $fieldsByObjectId
     * @param  ManifestValidationError[]  $errors
     */
    private function validateBlocks(array $blocks, string $pathPrefix, array $objectsById, array $fieldsByObjectId, array $modalIdsInPage, array &$errors): void
    {
        foreach ($blocks as $i => $block) {
            $blockPath = "{$pathPrefix}/{$i}";

            // Recurse into every container shape (container, modal, tabs,
            // accordion, split_view) so a broken descendant is still caught.
            foreach ($this->childBlockLists($block) as $childPath => $childBlocks) {
                $this->validateBlocks(
                    $childBlocks,
                    "{$blockPath}/{$childPath}",
                    $objectsById,
                    $fieldsByObjectId,
                    $modalIdsInPage,
                    $errors,
                );
            }

            if ($block['type'] === 'container' || $block['type'] === 'modal'
                || $block['type'] === 'tabs' || $block['type'] === 'accordion'
                || $block['type'] === 'split_view') {
                continue;
            }

            if ($block['type'] === 'lead_form') {
                $objectId = $block['object_id'] ?? null;
                if ($objectId === null || ! isset($objectsById[$objectId])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/object_id",
                        "lead_form block references unknown object_id '{$objectId}'",
                        'unresolved_ref',
                    );

                    continue;
                }
                $fields = $fieldsByObjectId[$objectId] ?? [];
                foreach ($block['fields'] ?? [] as $j => $leadField) {
                    if (! isset($fields[$leadField['field_id']])) {
                        $errors[] = new ManifestValidationError(
                            "{$blockPath}/fields/{$j}/field_id",
                            "lead_form field_id '{$leadField['field_id']}' does not belong to object '{$objectId}'",
                            'unresolved_ref',
                        );
                    }
                }
            }

            if ($block['type'] === 'form') {
                $objectId = $block['object_id'] ?? null;
                if ($objectId === null || ! isset($objectsById[$objectId])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/object_id",
                        "form block references unknown object_id '{$objectId}'",
                        'unresolved_ref',
                    );

                    continue;
                }
                $fields = $fieldsByObjectId[$objectId] ?? [];
                foreach ($block['fields'] ?? [] as $j => $formField) {
                    if (! isset($fields[$formField['field_id']])) {
                        $errors[] = new ManifestValidationError(
                            "{$blockPath}/fields/{$j}/field_id",
                            "form field_id '{$formField['field_id']}' does not belong to object '{$objectId}'",
                            'unresolved_ref',
                        );
                    }
                    if (isset($formField['default_expression'])) {
                        $this->validateExpression($formField['default_expression'], "{$blockPath}/fields/{$j}/default_expression", 'default_expression', $errors);
                    }
                    if (isset($formField['readonly_expression'])) {
                        $this->validateExpression($formField['readonly_expression'], "{$blockPath}/fields/{$j}/readonly_expression", 'readonly_expression', $errors);
                    }
                    foreach (['visible_if', 'required_if'] as $condKey) {
                        if (isset($formField[$condKey])) {
                            $this->validateFieldCondition($formField[$condKey], $fields, $objectId, "{$blockPath}/fields/{$j}/{$condKey}", $errors);
                        }
                    }
                }
                if (($block['mode'] ?? null) === 'edit' && ! isset($block['record_id_expression'])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/record_id_expression",
                        'form with mode=edit requires record_id_expression',
                        'missing_required',
                    );
                }
                $this->validateActionSequence(
                    $block['on_submit'] ?? [],
                    "{$blockPath}/on_submit",
                    $objectsById,
                    $modalIdsInPage,
                    $errors,
                );
                $this->validateActionSequence(
                    $block['on_cancel'] ?? [],
                    "{$blockPath}/on_cancel",
                    $objectsById,
                    $modalIdsInPage,
                    $errors,
                );

                continue;
            }

            if ($block['type'] === 'multi_step_form') {
                $objectId = $block['object_id'] ?? null;
                if ($objectId === null || ! isset($objectsById[$objectId])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/object_id",
                        "multi_step_form block references unknown object_id '{$objectId}'",
                        'unresolved_ref',
                    );

                    continue;
                }
                $fields = $fieldsByObjectId[$objectId] ?? [];
                $seenFieldIds = [];
                foreach ($block['steps'] ?? [] as $sIdx => $step) {
                    foreach ($step['fields'] ?? [] as $fIdx => $stepField) {
                        $fid = $stepField['field_id'] ?? null;
                        if (! isset($fields[$fid])) {
                            $errors[] = new ManifestValidationError(
                                "{$blockPath}/steps/{$sIdx}/fields/{$fIdx}/field_id",
                                "multi_step_form field_id '{$fid}' does not belong to object '{$objectId}'",
                                'unresolved_ref',
                            );

                            continue;
                        }
                        if (isset($seenFieldIds[$fid])) {
                            $errors[] = new ManifestValidationError(
                                "{$blockPath}/steps/{$sIdx}/fields/{$fIdx}/field_id",
                                "multi_step_form field_id '{$fid}' appears in more than one step",
                                'duplicate_id',
                            );
                        }
                        $seenFieldIds[$fid] = true;

                        if (isset($stepField['default_expression'])) {
                            $this->validateExpression($stepField['default_expression'], "{$blockPath}/steps/{$sIdx}/fields/{$fIdx}/default_expression", 'default_expression', $errors);
                        }
                        if (isset($stepField['readonly_expression'])) {
                            $this->validateExpression($stepField['readonly_expression'], "{$blockPath}/steps/{$sIdx}/fields/{$fIdx}/readonly_expression", 'readonly_expression', $errors);
                        }
                        foreach (['visible_if', 'required_if'] as $condKey) {
                            if (isset($stepField[$condKey])) {
                                $this->validateFieldCondition($stepField[$condKey], $fields, $objectId, "{$blockPath}/steps/{$sIdx}/fields/{$fIdx}/{$condKey}", $errors);
                            }
                        }
                    }
                }
                if (($block['mode'] ?? null) === 'edit' && ! isset($block['record_id_expression'])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/record_id_expression",
                        'multi_step_form with mode=edit requires record_id_expression',
                        'missing_required',
                    );
                }
                $this->validateActionSequence(
                    $block['on_submit'] ?? [],
                    "{$blockPath}/on_submit",
                    $objectsById,
                    $modalIdsInPage,
                    $errors,
                );
                $this->validateActionSequence(
                    $block['on_cancel'] ?? [],
                    "{$blockPath}/on_cancel",
                    $objectsById,
                    $modalIdsInPage,
                    $errors,
                );

                continue;
            }

            if ($block['type'] === 'button') {
                $this->validateActionSequence(
                    $block['on_click'] ?? [],
                    "{$blockPath}/on_click",
                    $objectsById,
                    $modalIdsInPage,
                    $errors,
                );

                continue;
            }

            if ($block['type'] === 'hero') {
                if (isset($block['cta']['on_click'])) {
                    $this->validateActionSequence(
                        $block['cta']['on_click'],
                        "{$blockPath}/cta/on_click",
                        $objectsById,
                        $modalIdsInPage,
                        $errors,
                    );
                }

                // The hero's stat is the biggest number on the page and was the one
                // place nothing checked. That is exactly where the averaged rate landed.
                if (isset($block['stat']) && is_array($block['stat'])) {
                    $heroFields = $this->resolveBlockObjectFields(
                        $block['stat']['query'] ?? [], 'object_id',
                        "{$blockPath}/stat/query/object_id", 'hero.stat',
                        $objectsById, $fieldsByObjectId, $errors,
                    );
                    if ($heroFields !== null) {
                        $this->checkFieldRef(
                            $heroFields, $block['stat']['field_id'] ?? null,
                            "{$blockPath}/stat/field_id", 'hero.stat.field_id', $errors,
                            null, $fieldsByObjectId,
                        );
                        $this->checkDerivedRate(
                            $heroFields, $block['stat']['field_id'] ?? null,
                            (string) ($block['stat']['aggregation'] ?? ''), 'hero stat',
                            "{$blockPath}/stat/field_id", $errors,
                        );
                        $this->checkPercentageFormat(
                            $block['stat']['format'] ?? null,
                            (string) ($block['stat']['aggregation'] ?? ''),
                            isset($block['stat']['ratio_denominator']), 'hero stat',
                            "{$blockPath}/stat/format", $errors,
                        );
                    }
                }

                continue;
            }

            if ($block['type'] === 'cta') {
                if (isset($block['button']['on_click'])) {
                    $this->validateActionSequence(
                        $block['button']['on_click'],
                        "{$blockPath}/button/on_click",
                        $objectsById,
                        $modalIdsInPage,
                        $errors,
                    );
                }

                continue;
            }

            if ($block['type'] === 'pricing') {
                foreach ($block['tiers'] ?? [] as $tIdx => $tier) {
                    if (isset($tier['cta']['on_click'])) {
                        $this->validateActionSequence(
                            $tier['cta']['on_click'],
                            "{$blockPath}/tiers/{$tIdx}/cta/on_click",
                            $objectsById,
                            $modalIdsInPage,
                            $errors,
                        );
                    }
                }

                continue;
            }

            if ($block['type'] === 'table') {
                $objectId = $block['data_source']['object_id'] ?? null;
                if ($objectId === null || ! isset($objectsById[$objectId])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/data_source/object_id",
                        "table block references unknown object_id '{$objectId}'",
                        'unresolved_ref',
                    );

                    continue;
                }
                $fields = $fieldsByObjectId[$objectId] ?? [];
                foreach ($block['columns'] ?? [] as $j => $column) {
                    // Action columns are buttons-per-row — they don't reference
                    // a field, they reference an on_click action sequence that
                    // resolves {{row.*}} at click time.
                    if (($column['type'] ?? null) === 'action') {
                        $this->validateActionSequence(
                            $column['on_click'] ?? [],
                            "{$blockPath}/columns/{$j}/on_click",
                            $objectsById,
                            $modalIdsInPage,
                            $errors,
                        );

                        continue;
                    }

                    if (! isset($fields[$column['field_id']])
                        && RecordQueryService::systemField($column['field_id']) === null) {
                        $errors[] = new ManifestValidationError(
                            "{$blockPath}/columns/{$j}/field_id",
                            "column field_id '{$column['field_id']}' does not belong to object '{$objectId}'",
                            'unresolved_ref',
                        );
                    }
                }
                $this->validateFilterExpression(
                    $block['data_source']['filter'] ?? null,
                    "{$blockPath}/data_source/filter",
                    $fields,
                    $errors,
                );
                foreach ($block['data_source']['sort'] ?? [] as $j => $sort) {
                    if (! isset($fields[$sort['field_id']])
                        && RecordQueryService::systemField($sort['field_id']) === null) {
                        $errors[] = new ManifestValidationError(
                            "{$blockPath}/data_source/sort/{$j}/field_id",
                            "sort field_id '{$sort['field_id']}' does not belong to object '{$objectId}'",
                            'unresolved_ref',
                        );
                    }
                }
            }

            if ($block['type'] === 'stat') {
                $objectId = $block['query']['object_id'] ?? null;
                if ($objectId === null || ! isset($objectsById[$objectId])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/query/object_id",
                        "stat block references unknown object_id '{$objectId}'",
                        'unresolved_ref',
                    );

                    continue;
                }
                $fields = $fieldsByObjectId[$objectId] ?? [];
                $aggregation = $block['aggregation'];
                if ($aggregation !== 'count' && ! isset($block['field_id'])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/field_id",
                        "stat block with aggregation '{$aggregation}' requires field_id",
                        'missing_required',
                    );
                } elseif (isset($block['field_id']) && ! isset($fields[$block['field_id']])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/field_id",
                        "stat field_id '{$block['field_id']}' does not belong to object '{$objectId}'",
                        'unresolved_ref',
                    );
                } elseif (isset($block['field_id']) && in_array($aggregation, ['sum', 'avg', 'min', 'max'], true)) {
                    $field = $fields[$block['field_id']];
                    if (! in_array($field['type'], ['number', 'currency', 'rating', 'slider'], true)) {
                        $errors[] = new ManifestValidationError(
                            "{$blockPath}/field_id",
                            "aggregation '{$aggregation}' requires a numeric field, got '{$field['type']}'",
                            'incompatible_type',
                        );
                    }
                }
                $this->checkDerivedRate($fields, $block['field_id'] ?? null, $aggregation, 'stat', "{$blockPath}/field_id", $errors);
                $this->checkPercentageFormat($block['format'] ?? null, (string) $aggregation, isset($block['ratio_denominator']), 'stat block', "{$blockPath}/format", $errors);
                $this->validateFilterExpression(
                    $block['query']['filter'] ?? null,
                    "{$blockPath}/query/filter",
                    $fields,
                    $errors,
                );
            }

            if ($block['type'] === 'chart') {
                $fields = $this->resolveBlockObjectFields(
                    $block['data_source'] ?? [], 'object_id',
                    "{$blockPath}/data_source/object_id", 'chart',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                $aggregation = $block['aggregation'];
                // A combo/marea chart carries its measures inside `series[]` (each with
                // its own field_id), so the top-level y_field_id is ignored there — do
                // not require it when series is present.
                if ($aggregation !== 'count' && ! isset($block['y_field_id']) && ! isset($block['series'])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/y_field_id",
                        "chart block with aggregation '{$aggregation}' requires y_field_id (or a series[] array carrying the measures)",
                        'missing_required',
                    );
                }

                // A chart needs an AXIS. Without one there is nothing to plot the
                // measure against, so every row folds into a single bucket and the
                // chart draws exactly one bar — whatever the data, whatever the
                // chart_type. A real board shipped four of these: "OTD Diario",
                // "Retrasados por Día", a line and an area, each promising an
                // evolution over time and each rendering a single mark. It looked
                // entirely professional and said nothing.
                //
                // No chart type is exempt: a pie/donut/treemap/pareto/box/radar
                // needs group_by, a line/area/scatter needs x, a sankey needs both,
                // and a combo's `series` share one X too. A measure with no axis is
                // a single number — which is a `stat`, not a chart.
                if (! isset($block['group_by_field_id']) && ! isset($block['x_field_id'])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/group_by_field_id",
                        'A chart needs an axis: set group_by_field_id (the categories, or a date to bucket) or x_field_id. Without one, every row folds into a single bucket and the chart can only ever draw ONE bar. If you meant to show a single aggregate, use a `stat` block instead.',
                        'missing_required',
                    );
                }
                $this->checkFieldRef($fields, $block['y_field_id'] ?? null, "{$blockPath}/y_field_id", 'chart.y_field_id', $errors,
                    in_array($aggregation, ['sum', 'avg', 'min', 'max'], true) ? ['number', 'currency', 'rating', 'slider'] : null, $fieldsByObjectId);
                $this->checkFieldRef($fields, $block['x_field_id'] ?? null, "{$blockPath}/x_field_id", 'chart.x_field_id', $errors);
                $this->checkFieldRef($fields, $block['group_by_field_id'] ?? null, "{$blockPath}/group_by_field_id", 'chart.group_by_field_id', $errors);
                // series_field_id splits each category into stacked/grouped segments (bar charts).
                $this->checkFieldRef($fields, $block['series_field_id'] ?? null, "{$blockPath}/series_field_id", 'chart.series_field_id', $errors);
                $this->validateFilterExpression($block['data_source']['filter'] ?? null, "{$blockPath}/data_source/filter", $fields, $errors);
            }

            if ($block['type'] === 'record_detail') {
                $fields = $this->resolveBlockObjectFields(
                    $block, 'object_id',
                    "{$blockPath}/object_id", 'record_detail',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                foreach ($block['fields'] ?? [] as $j => $detailField) {
                    $this->checkFieldRef($fields, $detailField['field_id'] ?? null, "{$blockPath}/fields/{$j}/field_id", 'record_detail.field_id', $errors);
                }
                $this->validateExpression((string) ($block['record_id_expression'] ?? ''), "{$blockPath}/record_id_expression", 'record_id_expression', $errors);
            }

            if ($block['type'] === 'related_list') {
                $fields = $this->resolveBlockObjectFields(
                    $block, 'object_id',
                    "{$blockPath}/object_id", 'related_list',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                // via_relation_field_id must be a relation field ON the child object.
                $this->checkFieldRef($fields, $block['via_relation_field_id'] ?? null, "{$blockPath}/via_relation_field_id", 'related_list.via_relation_field_id', $errors, ['relation']);
                foreach ($block['columns'] ?? [] as $j => $column) {
                    $this->checkFieldRef($fields, $column['field_id'] ?? null, "{$blockPath}/columns/{$j}/field_id", 'related_list.field_id', $errors);
                }
                $this->validateExpression((string) ($block['parent_id_expression'] ?? ''), "{$blockPath}/parent_id_expression", 'parent_id_expression', $errors);
            }

            if ($block['type'] === 'kanban') {
                $fields = $this->resolveBlockObjectFields(
                    $block['data_source'] ?? [], 'object_id',
                    "{$blockPath}/data_source/object_id", 'kanban',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                $this->checkFieldRef($fields, $block['group_by_field_id'] ?? null, "{$blockPath}/group_by_field_id", 'kanban.group_by_field_id', $errors, ['single_select']);
                $this->checkFieldRef($fields, $block['card_title_field_id'] ?? null, "{$blockPath}/card_title_field_id", 'kanban.card_title_field_id', $errors);
                foreach ($block['card_meta_fields'] ?? [] as $j => $meta) {
                    $this->checkFieldRef($fields, $meta['field_id'] ?? null, "{$blockPath}/card_meta_fields/{$j}/field_id", 'kanban.card_meta_fields', $errors);
                }
                $this->validateFilterExpression($block['data_source']['filter'] ?? null, "{$blockPath}/data_source/filter", $fields, $errors);
            }

            if ($block['type'] === 'calendar') {
                $fields = $this->resolveBlockObjectFields(
                    $block['data_source'] ?? [], 'object_id',
                    "{$blockPath}/data_source/object_id", 'calendar',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                $this->checkFieldRef($fields, $block['date_field_id'] ?? null, "{$blockPath}/date_field_id", 'calendar.date_field_id', $errors, ['date', 'datetime']);
                $this->checkFieldRef($fields, $block['title_field_id'] ?? null, "{$blockPath}/title_field_id", 'calendar.title_field_id', $errors);
                $this->checkFieldRef($fields, $block['color_field_id'] ?? null, "{$blockPath}/color_field_id", 'calendar.color_field_id', $errors, ['single_select']);
                $this->validateFilterExpression($block['data_source']['filter'] ?? null, "{$blockPath}/data_source/filter", $fields, $errors);
            }

            if ($block['type'] === 'sparkline') {
                $fields = $this->resolveBlockObjectFields(
                    $block['data_source'] ?? [], 'object_id',
                    "{$blockPath}/data_source/object_id", 'sparkline',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                $aggregation = $block['aggregation'] ?? 'count';
                $this->checkFieldRef($fields, $block['x_field_id'] ?? null, "{$blockPath}/x_field_id", 'sparkline.x_field_id', $errors);
                $this->checkFieldRef($fields, $block['y_field_id'] ?? null, "{$blockPath}/y_field_id", 'sparkline.y_field_id', $errors,
                    in_array($aggregation, ['sum', 'avg', 'min', 'max'], true) ? ['number', 'currency', 'rating', 'slider'] : null, $fieldsByObjectId);
                if ($aggregation !== 'count' && ! isset($block['y_field_id'])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/y_field_id",
                        "sparkline aggregation '{$aggregation}' requires y_field_id",
                        'missing_required',
                    );
                }
                $this->validateFilterExpression($block['data_source']['filter'] ?? null, "{$blockPath}/data_source/filter", $fields, $errors);
            }

            // gauge (half-circle) and progress (linear bar) share the same
            // single-aggregate-against-max_value contract, so validate them alike.
            if ($block['type'] === 'gauge' || $block['type'] === 'progress') {
                $type = $block['type'];
                $fields = $this->resolveBlockObjectFields(
                    $block['query'] ?? [], 'object_id',
                    "{$blockPath}/query/object_id", $type,
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                $aggregation = $block['aggregation'];
                if ($aggregation !== 'count' && ! isset($block['field_id'])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/field_id",
                        "{$type} aggregation '{$aggregation}' requires field_id",
                        'missing_required',
                    );
                }
                $this->checkFieldRef($fields, $block['field_id'] ?? null, "{$blockPath}/field_id", "{$type}.field_id", $errors,
                    in_array($aggregation, ['sum', 'avg', 'min', 'max'], true) ? ['number', 'currency', 'rating', 'slider'] : null, $fieldsByObjectId);
                // A gauge has no ratio_denominator at all — it can only ever render
                // the aggregate of one column, so a derived rate has no honest form here.
                $this->checkDerivedRate($fields, $block['field_id'] ?? null, $aggregation, $type, "{$blockPath}/field_id", $errors);
                // gauge/progress have no ratio_denominator, so a percentage here can
                // only ever be a plain aggregate of one column.
                $this->checkPercentageFormat($block['format'] ?? null, (string) $aggregation, false, "{$type} block", "{$blockPath}/format", $errors);
                $this->validateFilterExpression($block['query']['filter'] ?? null, "{$blockPath}/query/filter", $fields, $errors);
            }

            if ($block['type'] === 'heatmap') {
                $fields = $this->resolveBlockObjectFields(
                    $block['data_source'] ?? [], 'object_id',
                    "{$blockPath}/data_source/object_id", 'heatmap',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                $this->checkFieldRef($fields, $block['date_field_id'] ?? null, "{$blockPath}/date_field_id", 'heatmap.date_field_id', $errors, ['date', 'datetime']);
                $this->validateFilterExpression($block['data_source']['filter'] ?? null, "{$blockPath}/data_source/filter", $fields, $errors);
            }

            if ($block['type'] === 'pivot') {
                $fields = $this->resolveBlockObjectFields(
                    $block['data_source'] ?? [], 'object_id',
                    "{$blockPath}/data_source/object_id", 'pivot',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                $this->checkFieldRef($fields, $block['group_by_field_id'] ?? null, "{$blockPath}/group_by_field_id", 'pivot.group_by_field_id', $errors);
                $this->checkFieldRef($fields, $block['column_field_id'] ?? null, "{$blockPath}/column_field_id", 'pivot.column_field_id', $errors);

                // A bucket truncates a date; on anything else it is meaningless,
                // and the query layer refuses it at run time — say so here instead.
                foreach ([['bucket', 'group_by_field_id'], ['column_bucket', 'column_field_id']] as [$bucketKey, $fieldKey]) {
                    if (isset($block[$bucketKey])) {
                        $this->checkFieldRef($fields, $block[$fieldKey] ?? null, "{$blockPath}/{$fieldKey}", "pivot.{$fieldKey} (required by {$bucketKey})", $errors, ['date', 'datetime']);
                    }
                }

                $aggregation = (string) ($block['aggregation'] ?? 'count');
                if ($aggregation !== 'count') {
                    // distinct_count counts the values of ANY field — a retention
                    // table counts customers, and a customer is not a number.
                    $allowed = $aggregation === 'distinct_count'
                        ? null
                        : ['number', 'currency', 'rating', 'slider'];
                    $this->checkFieldRef($fields, $block['y_field_id'] ?? null, "{$blockPath}/y_field_id", "pivot.y_field_id (required by aggregation '{$aggregation}')", $errors, $allowed);
                }

                // A cohort reads each row from its OWN beginning, so its columns
                // must be a date it can measure an offset along.
                if (($block['mode'] ?? 'matrix') === 'cohort' && ! isset($block['column_bucket'])) {
                    $errors[] = new ManifestValidationError(
                        "{$blockPath}/column_bucket",
                        'A cohort pivot needs column_bucket: its columns are periods since each cohort began, and an unbucketed date makes every timestamp its own column.',
                        'missing_required',
                        expected: ['day', 'week', 'month', 'quarter', 'year'],
                    );
                }

                $this->validateFilterExpression($block['data_source']['filter'] ?? null, "{$blockPath}/data_source/filter", $fields, $errors);
            }

            if ($block['type'] === 'timeline') {
                $fields = $this->resolveBlockObjectFields(
                    $block['data_source'] ?? [], 'object_id',
                    "{$blockPath}/data_source/object_id", 'timeline',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                $this->checkFieldRef($fields, $block['date_field_id'] ?? null, "{$blockPath}/date_field_id", 'timeline.date_field_id', $errors, ['date', 'datetime']);
                $this->checkFieldRef($fields, $block['title_field_id'] ?? null, "{$blockPath}/title_field_id", 'timeline.title_field_id', $errors);
                $this->checkFieldRef($fields, $block['description_field_id'] ?? null, "{$blockPath}/description_field_id", 'timeline.description_field_id', $errors);
                $this->checkFieldRef($fields, $block['color_field_id'] ?? null, "{$blockPath}/color_field_id", 'timeline.color_field_id', $errors, ['single_select']);
                $this->validateFilterExpression($block['data_source']['filter'] ?? null, "{$blockPath}/data_source/filter", $fields, $errors);
            }

            if ($block['type'] === 'gantt') {
                $fields = $this->resolveBlockObjectFields(
                    $block['data_source'] ?? [], 'object_id',
                    "{$blockPath}/data_source/object_id", 'gantt',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                $this->checkFieldRef($fields, $block['start_field_id'] ?? null, "{$blockPath}/start_field_id", 'gantt.start_field_id', $errors, ['date', 'datetime']);
                $this->checkFieldRef($fields, $block['end_field_id'] ?? null, "{$blockPath}/end_field_id", 'gantt.end_field_id', $errors, ['date', 'datetime']);
                $this->checkFieldRef($fields, $block['title_field_id'] ?? null, "{$blockPath}/title_field_id", 'gantt.title_field_id', $errors);
                $this->checkFieldRef($fields, $block['color_field_id'] ?? null, "{$blockPath}/color_field_id", 'gantt.color_field_id', $errors, ['single_select']);
                $this->validateFilterExpression($block['data_source']['filter'] ?? null, "{$blockPath}/data_source/filter", $fields, $errors);
            }

            if ($block['type'] === 'data_grid') {
                $fields = $this->resolveBlockObjectFields(
                    $block['data_source'] ?? [], 'object_id',
                    "{$blockPath}/data_source/object_id", 'data_grid',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                foreach ($block['columns'] ?? [] as $j => $column) {
                    $this->checkFieldRef($fields, $column['field_id'] ?? null, "{$blockPath}/columns/{$j}/field_id", 'data_grid.field_id', $errors);
                }
                $this->validateFilterExpression($block['data_source']['filter'] ?? null, "{$blockPath}/data_source/filter", $fields, $errors);
            }

            if ($block['type'] === 'map') {
                $fields = $this->resolveBlockObjectFields(
                    $block['data_source'] ?? [], 'object_id',
                    "{$blockPath}/data_source/object_id", 'map',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                $this->checkFieldRef($fields, $block['lat_field_id'] ?? null, "{$blockPath}/lat_field_id", 'map.lat_field_id', $errors, ['number']);
                $this->checkFieldRef($fields, $block['lng_field_id'] ?? null, "{$blockPath}/lng_field_id", 'map.lng_field_id', $errors, ['number']);
                $this->checkFieldRef($fields, $block['popup_field_id'] ?? null, "{$blockPath}/popup_field_id", 'map.popup_field_id', $errors);
                $this->checkFieldRef($fields, $block['color_field_id'] ?? null, "{$blockPath}/color_field_id", 'map.color_field_id', $errors, ['single_select']);
                $this->validateFilterExpression($block['data_source']['filter'] ?? null, "{$blockPath}/data_source/filter", $fields, $errors);
            }

            if ($block['type'] === 'card_grid') {
                $fields = $this->resolveBlockObjectFields(
                    $block['data_source'] ?? [], 'object_id',
                    "{$blockPath}/data_source/object_id", 'card_grid',
                    $objectsById, $fieldsByObjectId, $errors,
                );
                if ($fields === null) {
                    continue;
                }
                $this->checkFieldRef($fields, $block['title_field_id'] ?? null, "{$blockPath}/title_field_id", 'card_grid.title_field_id', $errors);
                $this->checkFieldRef($fields, $block['subtitle_field_id'] ?? null, "{$blockPath}/subtitle_field_id", 'card_grid.subtitle_field_id', $errors);
                $this->checkFieldRef($fields, $block['image_field_id'] ?? null, "{$blockPath}/image_field_id", 'card_grid.image_field_id', $errors);
                foreach ($block['meta_fields'] ?? [] as $j => $meta) {
                    $this->checkFieldRef($fields, $meta['field_id'] ?? null, "{$blockPath}/meta_fields/{$j}/field_id", 'card_grid.meta_fields', $errors);
                }
                $this->validateFilterExpression($block['data_source']['filter'] ?? null, "{$blockPath}/data_source/filter", $fields, $errors);
                if (isset($block['on_click'])) {
                    $this->validateActionSequence($block['on_click'], "{$blockPath}/on_click", $objectsById, $modalIdsInPage, $errors);
                }
            }

            if ($block['type'] === 'metric_grid') {
                foreach ($block['items'] ?? [] as $j => $item) {
                    $itemPath = "{$blockPath}/items/{$j}";
                    $fields = $this->resolveBlockObjectFields(
                        $item['query'] ?? [], 'object_id',
                        "{$itemPath}/query/object_id", 'metric_grid.item',
                        $objectsById, $fieldsByObjectId, $errors,
                    );
                    if ($fields === null) {
                        continue;
                    }
                    $aggregation = $item['aggregation'];
                    if ($aggregation !== 'count' && ! isset($item['field_id'])) {
                        $errors[] = new ManifestValidationError(
                            "{$itemPath}/field_id",
                            "metric_grid item with aggregation '{$aggregation}' requires field_id",
                            'missing_required',
                        );
                    }
                    $this->checkFieldRef($fields, $item['field_id'] ?? null, "{$itemPath}/field_id", 'metric_grid.item.field_id', $errors,
                        in_array($aggregation, ['sum', 'avg', 'min', 'max'], true) ? ['number', 'currency', 'rating', 'slider'] : null, $fieldsByObjectId);
                    $this->checkDerivedRate($fields, $item['field_id'] ?? null, $aggregation, 'metric_grid item', "{$itemPath}/field_id", $errors);
                    $this->checkPercentageFormat($item['format'] ?? null, (string) $aggregation, isset($item['ratio_denominator']), 'metric_grid item', "{$itemPath}/format", $errors);
                    $this->validateFilterExpression($item['query']['filter'] ?? null, "{$itemPath}/query/filter", $fields, $errors);
                }
            }

            if ($block['type'] === 'funnel') {
                foreach ($block['stages'] ?? [] as $j => $stage) {
                    $stagePath = "{$blockPath}/stages/{$j}";
                    $fields = $this->resolveBlockObjectFields(
                        $stage['query'] ?? [], 'object_id',
                        "{$stagePath}/query/object_id", 'funnel.stage',
                        $objectsById, $fieldsByObjectId, $errors,
                    );
                    if ($fields === null) {
                        continue;
                    }
                    $aggregation = $stage['aggregation'];
                    if ($aggregation !== 'count' && ! isset($stage['field_id'])) {
                        $errors[] = new ManifestValidationError(
                            "{$stagePath}/field_id",
                            "funnel stage with aggregation '{$aggregation}' requires field_id",
                            'missing_required',
                        );
                    }
                    $this->checkFieldRef($fields, $stage['field_id'] ?? null, "{$stagePath}/field_id", 'funnel.stage.field_id', $errors,
                        in_array($aggregation, ['sum', 'avg'], true) ? ['number', 'currency', 'rating', 'slider'] : null, $fieldsByObjectId);
                    $this->validateFilterExpression($stage['query']['filter'] ?? null, "{$stagePath}/query/filter", $fields, $errors);
                }
            }
        }
    }

    /**
     * Resolve the fields map for a block's object reference. Pushes an error
     * and returns null if the object_id is missing or doesn't resolve — so the
     * caller can short-circuit further checks that all assume a valid object.
     *
     * @param  array<string, mixed>  $source  the sub-object that holds object_id (e.g. data_source, query)
     * @param  array<string, array<string, mixed>>  $objectsById
     * @param  array<string, array<string, array<string, mixed>>>  $fieldsByObjectId
     * @param  ManifestValidationError[]  $errors
     * @return array<string, array<string, mixed>>|null
     */
    private function resolveBlockObjectFields(array $source, string $key, string $errorPath, string $blockLabel, array $objectsById, array $fieldsByObjectId, array &$errors): ?array
    {
        $objectId = $source[$key] ?? null;
        if ($objectId === null || ! isset($objectsById[$objectId])) {
            $errors[] = new ManifestValidationError(
                $errorPath,
                "{$blockLabel} block references unknown object_id '{$objectId}'",
                'unresolved_ref',
            );

            return null;
        }

        return $fieldsByObjectId[$objectId] ?? [];
    }

    /**
     * Check a field_id reference against the fields of the block's queried
     * object. No-op for null (the caller decides whether the ref is required).
     * If $allowedTypes is set, also enforces that the field's type is one of
     * them — used to reject e.g. a string field passed as a numeric y_field_id.
     * A headline number must not be the average of a rate the data proves is derived.
     *
     * `avg(otd_pct)` weights a day with 3 orders exactly like a day with 500, so it
     * is not the rate — it is a different number, and one nobody can defend in a
     * board meeting. `sum` of a percentage is worse. The identity was proven from
     * the rows when the object was modelled and recorded on the field, so this can
     * be enforced from the manifest alone, on every edit, forever.
     *
     * Advice was tried first: the authoring tool already says "NEVER aggregate this
     * with avg" and prints both numbers. The very next build put avg(otd_pct) in the
     * hero anyway. A rule a model may decline is not a rule.
     *
     * min/max/median are left alone on purpose: the worst day's rate is a real fact
     * about the distribution. Only avg and sum claim to BE the overall rate.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  ManifestValidationError[]  $errors
     */
    private function checkDerivedRate(array $fields, ?string $fieldId, string $aggregation, string $what, string $errorPath, array &$errors): void
    {
        if ($fieldId === null || ! in_array($aggregation, ['avg', 'sum'], true)) {
            return;
        }

        $field = $fields[$fieldId] ?? null;
        $derived = is_array($field) ? ($field['derived_rate'] ?? null) : null;
        if (! is_array($derived)) {
            return;
        }

        $nameOf = fn (?string $id): string => (string) ($fields[$id]['name'] ?? $id ?? '?');
        $rate = $nameOf($fieldId);
        $numerator = $derived['numerator_field_id'] ?? null;
        $minus = $derived['minus_field_id'] ?? null;
        $denominator = $derived['denominator_field_id'] ?? null;
        $rows = $derived['verified_on_rows'] ?? null;

        $formula = $minus !== null
            ? "({$nameOf($numerator)} - {$nameOf($minus)}) / {$nameOf($denominator)}"
            : "{$nameOf($numerator)} / {$nameOf($denominator)}";
        $proof = $rows !== null ? " (proven on {$rows} sampled rows)" : '';

        $fix = $minus !== null
            ? "Its numerator is a DIFFERENCE, and ratio_denominator can only point at a single column, so NO KPI on this object can state this rate honestly. Do not put '{$rate}' in a {$what}: chart it per row (that is correct and useful), and show its components as the KPIs instead."
            : "Express it as a ratio: set field_id to '{$numerator}' with aggregation 'sum', and add ratio_denominator {query, aggregation: 'sum', field_id: '{$denominator}'} — the platform then recomputes SUM/SUM on every load, which IS the rate.";

        $errors[] = new ManifestValidationError(
            $errorPath,
            "'{$rate}' is a derived rate{$proof}: it equals {$formula} on every row. Aggregating it with '{$aggregation}' does not produce that rate — the mean of per-row rates weights a small row exactly like a big one, which is a different number, not an approximation. {$fix}",
            'incompatible_type',
        );
    }

    /**
     * A percentage KPI must be a RATIO. `format:"percentage"` on a fraction value
     * renders it ×100 in the UI (0.85 → "85%"), so a raw `sum`/`count` labelled as a
     * percentage blows up: summing 19,572 on-time products and formatting as a
     * percentage prints "1,957,286%" — the exact defect that shipped on a hero stat.
     * The honest forms are (a) a RATIO — field_id = numerator, aggregation 'sum',
     * plus ratio_denominator {query, aggregation:'sum', field_id: denominator} — whose
     * value is a 0..1 fraction, or (b) when the field already stores a per-row
     * percentage (0..100), an avg/min/max of it. sum/count formatted as a percentage
     * with no ratio_denominator is always wrong, so it is the one shape we reject.
     *
     * @param  ManifestValidationError[]  $errors
     */
    private function checkPercentageFormat(?string $format, string $aggregation, bool $hasRatioDenominator, string $what, string $errorPath, array &$errors): void
    {
        if ($format !== 'percentage' || $hasRatioDenominator) {
            return;
        }
        if (! in_array($aggregation, ['sum', 'count'], true)) {
            return;
        }

        $errors[] = new ManifestValidationError(
            $errorPath,
            "A {$what} with format 'percentage' and aggregation '{$aggregation}' but no ratio_denominator renders the raw {$aggregation} multiplied by 100 — e.g. summing 19,572 on-time products prints \"1,957,286%\". A percentage KPI must be a RATIO: keep field_id as the numerator with aggregation 'sum' and add ratio_denominator {query, aggregation: 'sum', field_id: <denominator>} (the platform recomputes SUM/SUM on every load). If the field already stores a per-row percentage (0-100), use aggregation 'avg' (or min/max) instead of '{$aggregation}'.",
            'incompatible_type',
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  list<string>|null  $allowedTypes
     * @param  ManifestValidationError[]  $errors
     */
    private function checkFieldRef(array $fields, ?string $fieldId, string $errorPath, string $label, array &$errors, ?array $allowedTypes = null, ?array $fieldsByObjectId = null): void
    {
        if ($fieldId === null) {
            return;
        }

        // System fields (sys_created_at, sys_updated_at) are always resolvable
        // and live outside the manifest's per-object field list.
        $sysField = RecordQueryService::systemField($fieldId);
        $resolved = $sysField ?? $fields[$fieldId] ?? null;

        if ($resolved === null) {
            $errors[] = new ManifestValidationError(
                $errorPath,
                "{$label} '{$fieldId}' does not belong to the queried object",
                'unresolved_ref',
            );

            return;
        }

        if ($allowedTypes !== null) {
            $fieldType = $resolved['type'] ?? null;
            $ok = in_array($fieldType, $allowedTypes, true)
                || $this->derivedSatisfiesNumeric($resolved, $allowedTypes)
                // A lookup is numeric when the field it ultimately points at is —
                // needs the cross-object map to follow the (possibly chained) hop.
                || ($fieldType === 'lookup'
                    && in_array('number', $allowedTypes, true)
                    && $fieldsByObjectId !== null
                    && $this->lookupResolvesToNumeric($resolved, $fields, $fieldsByObjectId, 0));
            if (! $ok) {
                $errors[] = new ManifestValidationError(
                    $errorPath,
                    "{$label} requires a field of type ".implode('|', $allowedTypes).", got '{$fieldType}'",
                    'incompatible_type',
                );
            }
        }
    }

    /**
     * Whether a lookup field ultimately resolves to a numeric value: follow its
     * relation to the target object's field; if that target is itself a lookup,
     * recurse (chained lookups), bounded by a small depth guard against cycles.
     * Mirrors how DerivedFieldsResolver enriches chained lookups at runtime.
     *
     * @param  array<string, mixed>  $lookup
     * @param  array<string, array<string, mixed>>  $fields  the lookup's own object's fields by id
     * @param  array<string, array<string, array<string, mixed>>>  $fieldsByObjectId
     */
    private function lookupResolvesToNumeric(array $lookup, array $fields, array $fieldsByObjectId, int $depth): bool
    {
        if ($depth >= 5) {
            return false;
        }

        $via = $fields[$lookup['via_relation_field_id'] ?? ''] ?? null;
        if (($via['type'] ?? null) !== 'relation') {
            return false;
        }

        $targetFields = $fieldsByObjectId[$via['target_object_id'] ?? ''] ?? [];
        $target = $targetFields[$lookup['target_field_id'] ?? ''] ?? null;
        if ($target === null) {
            return false;
        }

        return match ($target['type'] ?? null) {
            'number', 'currency', 'rating', 'slider' => true,
            'formula' => ($target['return_type'] ?? null) === 'number',
            'rollup' => true,
            'lookup' => $this->lookupResolvesToNumeric($target, $targetFields, $fieldsByObjectId, $depth + 1),
            default => false,
        };
    }

    /**
     * A derived field can stand in where a numeric field is required when its
     * computed value is numeric: a `formula` with return_type number, or any
     * `rollup` (count/sum/avg/min/max all fold to a number). Used both for block
     * aggregation refs (metric_grid/chart/…, aggregated in PHP by
     * RecordQueryService::aggregateDerived) and for a rollup's target field (the
     * children's derived values are resolved before folding — so a parent rollup
     * can sum a per-child formula). Only applies when the requirement set is the
     * numeric one — never relaxes e.g. a single_select group_by. Lookups are
     * excluded: their numeric-ness depends on the target object's field, which
     * this local check can't resolve.
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $allowedTypes
     */
    private function derivedSatisfiesNumeric(array $field, array $allowedTypes): bool
    {
        if (! in_array('number', $allowedTypes, true)) {
            return false;
        }

        return match ($field['type'] ?? null) {
            'formula' => ($field['return_type'] ?? null) === 'number',
            'rollup' => true,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>|null  $expr
     * @param  array<string, array<string, mixed>>  $fields
     * @param  ManifestValidationError[]  $errors
     */
    private function validateFilterExpression(?array $expr, string $path, array $fields, array &$errors, bool $forTrigger = false): void
    {
        if ($expr === null) {
            return;
        }

        if (in_array($expr['op'] ?? null, ['and', 'or'], true)) {
            foreach ($expr['conditions'] ?? [] as $i => $cond) {
                $this->validateFilterExpression($cond, "{$path}/conditions/{$i}", $fields, $errors, $forTrigger);
            }

            return;
        }

        if (($expr['op'] ?? null) === 'not') {
            $this->validateFilterExpression($expr['condition'] ?? null, "{$path}/condition", $fields, $errors, $forTrigger);

            return;
        }

        // A trigger filter is matched against the single written record in memory,
        // so it can't traverse relations or evaluate a dynamic value_expression
        // (no DB query, no workflow context exists yet). Reject both with a
        // pointer to the right tool — a record.query + branch/skip_if step.
        if ($forTrigger && ($expr['op'] ?? null) === 'related') {
            $errors[] = new ManifestValidationError(
                "{$path}/op",
                "trigger filters can't traverse relations ('related'); fire on the record event, then check the relation inside the workflow with a record.query step and a branch/skip_if.",
                'unsupported_in_trigger',
            );

            return;
        }
        if ($forTrigger && isset($expr['value_expression'])) {
            $errors[] = new ManifestValidationError(
                "{$path}/value_expression",
                'trigger filters compare against a literal `value`, not `value_expression` — there is no workflow context to resolve it against at trigger time.',
                'unsupported_in_trigger',
            );

            return;
        }

        // Relation traversal: field_id must be a relation on THIS object; its
        // sub-condition is scoped to the RELATED object's fields.
        if (($expr['op'] ?? null) === 'related') {
            $relField = $fields[$expr['field_id'] ?? ''] ?? null;
            if (($relField['type'] ?? null) !== 'relation') {
                $errors[] = new ManifestValidationError(
                    "{$path}/field_id",
                    "related filter field_id '".($expr['field_id'] ?? '')."' is not a relation field on the queried object",
                    'unresolved_ref',
                );

                return;
            }
            $targetFields = $this->fieldsByObjectId[$relField['target_object_id'] ?? ''] ?? [];
            $this->validateFilterExpression($expr['condition'] ?? null, "{$path}/condition", $targetFields, $errors);

            return;
        }

        if (isset($expr['field_id'])
            && ! isset($fields[$expr['field_id']])
            && RecordQueryService::systemField($expr['field_id']) === null) {
            $errors[] = new ManifestValidationError(
                "{$path}/field_id",
                "filter field_id '{$expr['field_id']}' does not belong to the queried object",
                'unresolved_ref',
            );
        }

        if (isset($expr['value_expression'])) {
            $this->validateExpression($expr['value_expression'], "{$path}/value_expression", 'value_expression', $errors);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $pagesById
     * @param  ManifestValidationError[]  $errors
     */
    private function validateNavigation(array $items, string $pathPrefix, array $pagesById, array &$errors): void
    {
        foreach ($items as $i => $item) {
            if (isset($item['page_id']) && ! isset($pagesById[$item['page_id']])) {
                $errors[] = new ManifestValidationError(
                    "{$pathPrefix}/{$i}/page_id",
                    "navigation item page_id '{$item['page_id']}' does not match any defined page",
                    'unresolved_ref',
                );
            }
            if (isset($item['children'])) {
                $this->validateNavigation($item['children'], "{$pathPrefix}/{$i}/children", $pagesById, $errors);
            }
        }
    }

    /**
     * Recursively collect ids of all `modal` blocks inside a block tree so
     * open_modal/close_modal actions can be validated against them. Descends
     * through every container shape (container, modal, tabs, accordion,
     * split_view) so a modal nested inside any of them is still discovered.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, true>  $collected
     */
    private function collectModalIds(array $blocks, array &$collected): void
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'modal') {
                $collected[$block['id']] = true;
            }
            foreach ($this->childBlockLists($block) as $childBlocks) {
                $this->collectModalIds($childBlocks, $collected);
            }
        }
    }

    /**
     * Yield every nested block list inside `$block`, keyed by the JSON-Pointer
     * fragment used to address it. Containers/modals nest under `blocks/N`;
     * tabs nest under `tabs/I/blocks/N`; accordion under `sections/I/blocks/N`;
     * split_view under `left_blocks/N` and `right_blocks/N`. Returning the
     * paths lets callers build accurate error paths during recursion.
     *
     * @param  array<string, mixed>  $block
     * @return iterable<string, list<array<string, mixed>>>
     */
    private function childBlockLists(array $block): iterable
    {
        $type = $block['type'] ?? null;

        if ($type === 'container' || $type === 'modal') {
            if (isset($block['blocks']) && is_array($block['blocks'])) {
                yield 'blocks' => $block['blocks'];
            }

            return;
        }

        if ($type === 'tabs') {
            foreach ($block['tabs'] ?? [] as $i => $tab) {
                if (isset($tab['blocks']) && is_array($tab['blocks'])) {
                    yield "tabs/{$i}/blocks" => $tab['blocks'];
                }
            }

            return;
        }

        if ($type === 'accordion') {
            foreach ($block['sections'] ?? [] as $i => $section) {
                if (isset($section['blocks']) && is_array($section['blocks'])) {
                    yield "sections/{$i}/blocks" => $section['blocks'];
                }
            }

            return;
        }

        if ($type === 'split_view') {
            if (isset($block['left_blocks']) && is_array($block['left_blocks'])) {
                yield 'left_blocks' => $block['left_blocks'];
            }
            if (isset($block['right_blocks']) && is_array($block['right_blocks'])) {
                yield 'right_blocks' => $block['right_blocks'];
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $sequence
     * @param  array<string, array<string, mixed>>  $objectsById
     * @param  array<string, true>  $modalIdsInPage
     * @param  ManifestValidationError[]  $errors
     */
    private function validateActionSequence(array $sequence, string $pathPrefix, array $objectsById, array $modalIdsInPage, array &$errors): void
    {
        foreach ($sequence as $i => $action) {
            $path = "{$pathPrefix}/{$i}";
            $type = $action['type'] ?? null;

            if (in_array($type, ['create_record', 'update_record', 'delete_record'], true)) {
                $objectId = $action['object_id'] ?? null;
                if ($objectId === null || ! isset($objectsById[$objectId])) {
                    $errors[] = new ManifestValidationError(
                        "{$path}/object_id",
                        "action '{$type}' references unknown object_id '{$objectId}'",
                        'unresolved_ref',
                    );
                }
                if (in_array($type, ['update_record', 'delete_record'], true)) {
                    if (! isset($action['record_id_expression'])) {
                        $errors[] = new ManifestValidationError(
                            "{$path}/record_id_expression",
                            "action '{$type}' requires a well-formed record_id_expression",
                            'malformed_expression',
                        );
                    } else {
                        $this->validateExpression((string) $action['record_id_expression'], "{$path}/record_id_expression", 'record_id_expression', $errors);
                    }
                }
                foreach ($action['values'] ?? [] as $slug => $valueExpr) {
                    if (! is_string($valueExpr)) {
                        continue;
                    }
                    $this->validateExpression($valueExpr, "{$path}/values/{$slug}", "values.{$slug}", $errors);
                }
            }

            if ($type === 'open_modal') {
                $modalId = $action['modal_block_id'] ?? null;
                if ($modalId === null || ! isset($modalIdsInPage[$modalId])) {
                    $errors[] = new ManifestValidationError(
                        "{$path}/modal_block_id",
                        "open_modal references modal '{$modalId}' which is not declared in this page",
                        'unresolved_ref',
                    );
                }
            }

            if ($type === 'close_modal' && isset($action['modal_block_id'])) {
                if (! isset($modalIdsInPage[$action['modal_block_id']])) {
                    $errors[] = new ManifestValidationError(
                        "{$path}/modal_block_id",
                        "close_modal references modal '{$action['modal_block_id']}' which is not declared in this page",
                        'unresolved_ref',
                    );
                }
            }
        }
    }

    /**
     * DFS over the formula→formula reference graph, tracking the current
     * path. Returns true if `start` reaches itself.
     *
     * @param  array<string, array<string, mixed>>  $formulasBySlug
     * @param  array<string, true>  $path
     */
    private function formulaHasCycle(string $start, array $formulasBySlug, array $path): bool
    {
        if (isset($path[$start])) {
            return true;
        }
        $path[$start] = true;
        $self = $formulasBySlug[$start] ?? null;
        if ($self === null) {
            return false;
        }
        $refs = $this->extractFormulaReferences((string) ($self['expression'] ?? ''));
        foreach ($refs as $refSlug) {
            if (isset($formulasBySlug[$refSlug]) && $this->formulaHasCycle($refSlug, $formulasBySlug, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pull out the field slugs a formula expression references. We only need
     * approximate accuracy for cycle detection — a false positive (extra slug)
     * would just trigger a (recoverable) cycle warning.
     *
     * @return list<string>
     */
    private function extractFormulaReferences(string $expression): array
    {
        preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', $expression, $matches);

        return $matches[1] ?? [];
    }

    /**
     * Extract the variable identifiers a formula's {{…}} tokens reference — the
     * names that must resolve to a field. Unlike extractFormulaReferences (which
     * only matches a lone `{{slug}}` token, enough for cycle detection), this
     * inspects the inside of every token so `{{monto * 1.16}}` and
     * `{{upper(apellido)}}` yield `monto` / `apellido`. String literals, function
     * names (identifier followed by `(`), member-access properties (identifier
     * after a `.`) and the expression language's named operators/literals are
     * excluded, so only genuine top-level variable references remain.
     *
     * @return list<string>
     */
    private function formulaVariableReferences(string $expression): array
    {
        if (preg_match_all('/\{\{\s*(.+?)\s*\}\}/s', $expression, $tokens) === false) {
            return [];
        }

        $reserved = ['and', 'or', 'not', 'xor', 'in', 'matches', 'contains', 'true', 'false', 'null'];
        $functions = SafeExpressionEvaluator::FUNCTIONS;

        $refs = [];
        foreach ($tokens[1] ?? [] as $inner) {
            $stripped = preg_replace('/([\'"]).*?\1/s', '', $inner) ?? $inner;

            if (preg_match_all('/(\.)?([a-zA-Z_]\w*)\s*(\()?/', $stripped, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $precededByDot = $match[1] === '.';
                    $name = $match[2];
                    $isCall = ($match[3] ?? '') === '(';

                    if ($precededByDot || $isCall
                        || in_array($name, $reserved, true)
                        || in_array($name, $functions, true)) {
                        continue;
                    }

                    $refs[$name] = true;
                }
            }
        }

        return array_keys($refs);
    }

    private function cardinalitiesMatch(string $a, string $b): bool
    {
        $inverse = [
            'one_to_one' => 'one_to_one',
            'one_to_many' => 'many_to_one',
            'many_to_one' => 'one_to_many',
            'many_to_many' => 'many_to_many',
        ];

        return ($inverse[$a] ?? null) === $b;
    }

    /**
     * Validates that '{{' opens match '}}' closes AND that each token is
     * lexically sound — the expression lexer flags unbalanced parentheses,
     * unterminated strings and stray characters at write-time. It stays lenient
     * on grammar the evaluator handles via its legacy fallback (e.g. numeric
     * dotted indices like `rows.0`), so it never rejects a working expression.
     */
    private function isWellFormedExpression(string $expr): bool
    {
        if (substr_count($expr, '{{') !== substr_count($expr, '}}')) {
            return false;
        }

        if (preg_match_all('/\{\{\s*(.+?)\s*\}\}/', $expr, $matches) === false) {
            return true;
        }

        foreach ($matches[1] as $inner) {
            try {
                $this->lexer()->tokenize($inner);
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    private function lexer(): Lexer
    {
        return $this->lexer ??= new Lexer;
    }

    /**
     * Validates an expression string and records a specific error if it is
     * malformed or calls something outside the function catalog. The
     * unknown-function message points the author (often the builder AI) at the
     * `script.run` workflow step rather than inviting them to invent a function.
     *
     * @param  list<ManifestValidationError>  $errors
     */
    private function validateExpression(string $expr, string $path, string $label, array &$errors): void
    {
        if (! $this->isWellFormedExpression($expr)) {
            $errors[] = new ManifestValidationError(
                $path,
                "{$label} has unbalanced or malformed '{{...}}' braces",
                'malformed_expression',
            );

            return;
        }

        $issue = $this->expressionFunctionIssue($expr);
        if ($issue !== null) {
            $allowed = implode(', ', SafeExpressionEvaluator::FUNCTIONS);
            $errors[] = new ManifestValidationError(
                $path,
                "{$label}: {$issue}. The expression language only supports these functions: {$allowed}. For logic beyond them (loops, parsing, multi-step transforms) use a 'script.run' workflow step that computes the value and writes it into a field — do not call other functions inline.",
                'unknown_function',
            );
        }
    }

    /**
     * Returns a human-readable reason if the expression calls something outside
     * the catalog, else null. Quoted string literals are stripped first so a
     * "(" inside text isn't mistaken for a call; EL's named operators are
     * allowed alongside the function catalog. Method-style calls (x.foo()) do
     * not exist in this dialect, so any ".name(" is flagged.
     */
    private function expressionFunctionIssue(string $expr): ?string
    {
        if (preg_match_all('/\{\{\s*(.+?)\s*\}\}/', $expr, $matches) === false) {
            return null;
        }

        $allowed = array_merge(
            SafeExpressionEvaluator::FUNCTIONS,
            ['not', 'and', 'or', 'xor', 'in', 'matches', 'contains'],
        );

        foreach ($matches[1] ?? [] as $inner) {
            $stripped = preg_replace('/([\'"]).*?\1/', '', $inner) ?? $inner;

            if (preg_match('/\.\s*[a-zA-Z_]\w*\s*\(/', $stripped)) {
                return 'JS-style method calls (e.g. x.toFixed(), Math.random()) are not supported';
            }

            if (preg_match_all('/([a-zA-Z_]\w*)\s*\(/', $stripped, $calls)) {
                foreach ($calls[1] as $name) {
                    if (! in_array($name, $allowed, true)) {
                        return "calls unknown function {$name}()";
                    }
                }
            }
        }

        return null;
    }

    /**
     * A visible_if / required_if condition must reference a field that belongs
     * to the form's object (the operator enum is already enforced by the JSON
     * schema). $fields is keyed by field_id.
     *
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $fields
     * @param  list<ManifestValidationError>  $errors
     */
    private function validateFieldCondition(array $condition, array $fields, string $objectId, string $path, array &$errors): void
    {
        $fieldId = $condition['field_id'] ?? null;
        if ($fieldId === null || ! isset($fields[$fieldId])) {
            $errors[] = new ManifestValidationError(
                "{$path}/field_id",
                "condition field_id '{$fieldId}' does not belong to object '{$objectId}'",
                'unresolved_ref',
            );
        }
    }

    private function opis(): OpisValidator
    {
        if ($this->opis === null) {
            $validator = new OpisValidator;
            // Report ALL schema violations in one pass instead of stopping at the
            // first. A model authoring a manifest can then fix every structural
            // error in a single edit rather than rediscovering them one round-trip
            // at a time. (Opis defaults to maxErrors=1 / stopAtFirstError=true.)
            //
            // KNOWN OPIS QUIRK: with maxErrors > 1 the `block` oneOf can judge a
            // VALID block invalid depending on which sibling defs exist / their
            // order. validateSchema() therefore CONFIRMS any failure against the
            // strict (maxErrors=1) validator below before trusting it.
            $validator->setMaxErrors(self::MAX_SCHEMA_ERRORS)->setStopAtFirstError(false);
            $resolver = $validator->resolver();
            if ($resolver !== null) {
                $resolver->registerFile(self::SCHEMA_URI, $this->resolveSchemaPath());
            }
            $this->opis = $validator;
        }

        return $this->opis;
    }

    /**
     * Opis in its battle-tested default mode (maxErrors=1) — the confirmation
     * pass validateSchema() runs before trusting a multi-error failure.
     */
    private function strictOpis(): OpisValidator
    {
        if ($this->strictOpis === null) {
            $validator = new OpisValidator;
            $validator->setMaxErrors(1)->setStopAtFirstError(false);
            $resolver = $validator->resolver();
            if ($resolver !== null) {
                $resolver->registerFile(self::SCHEMA_URI, $this->resolveSchemaPath());
            }
            $this->strictOpis = $validator;
        }

        return $this->strictOpis;
    }

    /**
     * The raw App-manifest JSON Schema as an associative array — the authoritative
     * contract (required keys, enums, per-type props, descriptions). Exposed so a
     * tool can hand it to a model to author against, instead of guessing from prose.
     *
     * @return array<string, mixed>
     */
    public function schemaArray(): array
    {
        return json_decode(
            (string) file_get_contents($this->resolveSchemaPath()),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function resolveSchemaPath(): string
    {
        if ($this->schemaPath !== null) {
            return $this->schemaPath;
        }

        // Resolve relative to the source file so unit tests that don't boot Laravel
        // can still load the schema. Production calls can pass an explicit path.
        return dirname(__DIR__, 3).'/'.self::SCHEMA_PATH;
    }

    /**
     * Opis expects schema data as native PHP objects (stdClass), not assoc arrays.
     *
     * @param  array<string, mixed>  $data
     */
    private function toJsonObject(array $data): mixed
    {
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
