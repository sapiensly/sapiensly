<?php

namespace App\Services\Import;

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Storage\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The whole import, in the two steps a user experiences it as: PLAN (what would
 * happen) and RUN (make it happen). Every surface — the builder upload, the
 * builder AI tool, MCP — goes through this, so a file imported by a person and
 * the same file imported by an agent produce the same schema and the same rows.
 *
 * The split matters. Analysing is free and reversible; importing five thousand
 * rows under the wrong types is neither. Nothing here writes until run().
 */
class ImportService
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly ImportPlanner $planner,
        private readonly RecordImporter $importer,
        private readonly AppManifestService $manifests,
    ) {}

    public function readFile(string $path, ?string $originalName = null): SheetData
    {
        return $this->reader->readFile($path, $originalName);
    }

    public function readString(string $contents): SheetData
    {
        return $this->reader->readString($contents);
    }

    /**
     * Read bytes of unknown format — what a file pulled off a storage disk
     * hands us, where there is no path to hand a parser.
     */
    public function readBytes(string $contents, ?string $originalName = null): SheetData
    {
        return $this->reader->readBytes($contents, $originalName);
    }

    /**
     * Park a file where the import JOB can read it, and say where.
     *
     * The tenant disk is preferred because a queue worker is frequently not the
     * machine that took the upload; `local` is the single-host fallback when no
     * tenant storage is configured. Shared by every surface that starts an
     * import so they cannot disagree about where files live.
     *
     * @return array{disk: string, path: string}
     */
    public function stash(App $app, string $contents, string $extension = 'csv'): array
    {
        try {
            $disk = app(TenantStorage::class)->diskName($app);
        } catch (\Throwable) {
            $disk = 'local';
        }

        $extension = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $extension)) ?: 'csv';
        $path = 'imports/'.$app->id.'/'.Str::ulid().'.'.$extension;

        Storage::disk($disk)->put($path, $contents);

        return ['disk' => $disk, 'path' => $path];
    }

    /**
     * Plan the import. With no `$objectSlug` the file defines a NEW object;
     * with one, it feeds an object that already exists.
     *
     * @param  array<string, string>  $overrides  header => field slug
     *
     * @throws InvalidArgumentException when the named object does not exist
     */
    public function plan(
        App $app,
        SheetData $sheet,
        ?string $objectSlug = null,
        array $overrides = [],
        ?string $upsertKeyHeader = null,
        ?string $objectName = null,
    ): ImportPlan {
        if ($objectSlug === null) {
            return $this->planner->planNewObject($sheet, $objectName ?: 'Datos importados');
        }

        $manifest = $this->manifests->getActiveManifest($app) ?? [];
        $object = $this->findObject($manifest, $objectSlug);

        if ($object === null) {
            throw new InvalidArgumentException("This app has no object '{$objectSlug}'.");
        }

        return $this->planner->planExistingObject($sheet, $object, $overrides, $upsertKeyHeader);
    }

    /**
     * Execute a plan. When it creates an object, the schema change is committed
     * as its own manifest version FIRST — so if the row import then goes badly,
     * the user is left with a real, reviewable, revertible object rather than
     * rows referring to a schema that was never saved.
     */
    public function run(App $app, SheetData $sheet, ImportPlan $plan, ?User $user = null, ?callable $onProgress = null): ImportResult
    {
        $manifest = $this->manifests->getActiveManifest($app);
        if ($manifest === null) {
            throw new InvalidArgumentException('This app has no manifest to import into.');
        }

        if ($plan->mode === ImportPlan::MODE_CREATE) {
            $manifest['objects'][] = $plan->object;
            $this->manifests->createVersion(
                $app,
                $manifest,
                $user,
                'Imported the object «'.$plan->object['name'].'» from a file',
            );
            // Re-read so the import writes against the manifest as SAVED
            // (ids filled, defaults applied) rather than the draft in memory.
            $manifest = $this->manifests->getActiveManifest($app->refresh()) ?? $manifest;
        }

        return $this->importer->import($app, $manifest, $plan, $sheet, $user, $onProgress);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function findObject(array $manifest, string $slugOrId): ?array
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            if (($object['slug'] ?? null) === $slugOrId || ($object['id'] ?? null) === $slugOrId) {
                return $object;
            }
        }

        return null;
    }
}
