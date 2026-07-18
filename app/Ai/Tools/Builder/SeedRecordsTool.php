<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordWriteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Lets Claude create real records in the tenant's database — closing the
 * loop that used to leave `/seed` stuck offering "Option 1 / 2 / 3" because
 * it had no way to actually insert data.
 *
 * The tool is intentionally narrow:
 *   - Accepts an `object_id_or_slug` so Claude can call it with whatever
 *     identifier feels natural ("clientes", "obj_01j..."); we resolve both.
 *   - Each record is `{slug: value}` keyed by the object's field slugs.
 *   - Validation is delegated to RecordWriteService::create() so the same
 *     rules that protect the runtime form path protect this one.
 *   - Hard cap on `MAX_RECORDS` per call prevents runaway turns. Claude can
 *     loop on the tool if it really needs more.
 */
class SeedRecordsTool implements Tool
{
    /**
     * Per-call ceiling on how many records Claude can insert at once. Keeps
     * a single tool invocation bounded so a hallucinated "1000" doesn't
     * pin the worker — Claude can simply chain another call if it wants
     * more.
     */
    private const MAX_RECORDS = 100;

    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private RecordWriteService $writer,
        private ?User $user = null,
        /**
         * Optional companion holding the running draft for the current turn.
         * Without this, seeding right after creating a brand-new object in
         * the same turn fails with "No object matched" because the object
         * lives only in the draft until the turn auto-applies at the end.
         */
        private ?ProposeChangeTool $proposeTool = null,
    ) {}

    public function name(): string
    {
        return 'seed_records';
    }

    public function description(): string
    {
        return 'Insert one or more new records into an object. Use this when the user asks for demo / seed / sample data. Each record is a {field_slug: value} map keyed by the OBJECT FIELDS (NOT field_ids). The response tells you how many records were created and lists any per-record validation errors. Hard cap '.self::MAX_RECORDS.' records per call — chain calls for more. Resolve the right object by calling read_manifest first if you are not sure which `object_id_or_slug` to pass.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_id_or_slug' => $schema
                ->string()
                ->description('The id (obj_…) OR slug of the object whose records you want to create.')
                ->required(),
            'records' => $schema
                ->array()
                ->description('Array of records. Each record is a JSON object keyed by FIELD SLUG (not field_id). For single_select fields, pass the option SLUG. For relation fields, pass the target record id OR a value that identifies the target record (e.g. its name) — it is resolved to the id; a value matching no record is rejected, so seed the parent object first. For booleans, true/false. For dates, ISO format (YYYY-MM-DD).')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $needle = trim((string) ($args['object_id_or_slug'] ?? ''));
        $records = $args['records'] ?? null;

        if ($needle === '') {
            return json_encode(['error' => 'object_id_or_slug is required'], JSON_THROW_ON_ERROR);
        }
        if (! is_array($records) || $records === []) {
            return json_encode(['error' => 'records must be a non-empty array'], JSON_THROW_ON_ERROR);
        }
        if (count($records) > self::MAX_RECORDS) {
            return json_encode([
                'error' => 'Too many records in a single call. Max is '.self::MAX_RECORDS.'. Split into multiple calls.',
            ], JSON_THROW_ON_ERROR);
        }

        // Prefer the running draft so objects added earlier in THIS turn are
        // visible. Falls back to the persisted manifest when no propose tool
        // is wired (e.g. running standalone in tests).
        $manifest = $this->proposeTool?->currentManifest()
            ?? $this->manifestService->getActiveManifest($this->appModel);
        if (! is_array($manifest)) {
            return json_encode(['error' => 'No active manifest found for this App.'], JSON_THROW_ON_ERROR);
        }

        // Resolve by id first, then by slug — covers both Claude calling
        // with the canonical id (after read_manifest) and the friendlier
        // slug the user typed in /seed.
        $object = null;
        foreach (($manifest['objects'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            if (($candidate['id'] ?? null) === $needle || ($candidate['slug'] ?? null) === $needle) {
                $object = $candidate;
                break;
            }
        }
        if ($object === null) {
            $available = array_values(array_filter(array_map(
                fn ($o) => is_array($o) ? ($o['slug'] ?? null) : null,
                $manifest['objects'] ?? [],
            )));

            return json_encode([
                'error' => "No object matched '{$needle}'.",
                'available_object_slugs' => $available,
            ], JSON_THROW_ON_ERROR);
        }

        $created = 0;
        $createdIds = [];
        $errors = [];

        foreach ($records as $index => $values) {
            if (! is_array($values)) {
                $errors[] = ['index' => $index, 'error' => 'Record must be a JSON object.'];

                continue;
            }
            try {
                $record = $this->writer->create($this->appModel, $manifest, $object['id'], $values, $this->user);
                $created++;
                $createdIds[] = $record->id;
            } catch (Throwable $e) {
                // We deliberately keep going — partial seeding is more
                // useful than a hard abort on the first bad row. Claude
                // sees the errors and can retry the failing ones.
                $errors[] = [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return json_encode([
            'object_id' => $object['id'],
            'object_slug' => $object['slug'] ?? null,
            'requested' => count($records),
            'created' => $created,
            'created_ids' => $createdIds,
            'errors' => $errors,
        ], JSON_THROW_ON_ERROR);
    }
}
