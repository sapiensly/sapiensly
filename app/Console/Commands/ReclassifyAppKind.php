<?php

namespace App\Console\Commands;

use App\Enums\AppKind;
use App\Models\App;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-derive every app's `kind` (App vs Dashboard) from its current manifest.
 * createVersion keeps `kind` in sync on each write, so this is only needed to
 * backfill apps that existed before the column, or after the classifier rules
 * change. Idempotent; runs on the app's normal connection (not a migration).
 */
#[Signature('apps:reclassify-kind')]
#[Description('Re-derive each app\'s kind (app/dashboard) from its current manifest.')]
class ReclassifyAppKind extends Command
{
    public function handle(): int
    {
        $updated = 0;

        App::query()->with('currentVersion')->chunkById(200, function ($apps) use (&$updated) {
            foreach ($apps as $app) {
                $manifest = $app->currentVersion?->manifest;
                if (! is_array($manifest)) {
                    continue;
                }
                $kind = AppKind::classify($manifest)->value;
                if (($app->kind?->value) !== $kind) {
                    $app->forceFill(['kind' => $kind])->saveQuietly();
                    $updated++;
                }
            }
        });

        $this->info("Reclassified {$updated} app(s).");

        return self::SUCCESS;
    }
}
