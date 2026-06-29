<?php

namespace App\Console\Commands\Tools;

use App\Services\Tools\LegacyToolConnectionMigrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tools:migrate-to-connections {--dry-run : Report what would change without writing} {--rollback : Restore migrated tools to their inline config}')]
#[Description('Migrate legacy self-contained rest_api/graphql tools onto Connections (integrations)')]
class MigrateToolsToConnections extends Command
{
    public function handle(LegacyToolConnectionMigrator $migrator): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('rollback')) {
            $result = $migrator->rollback($dryRun);
            foreach ($result['details'] as $line) {
                $this->line('  '.$line);
            }
            $this->info(($dryRun ? '[dry-run] ' : '')."Reverted {$result['reverted']} tool(s).");

            return self::SUCCESS;
        }

        $result = $migrator->migrate($dryRun);
        foreach ($result['details'] as $line) {
            $this->line('  '.$line);
        }
        $this->info(sprintf(
            '%sMigrated %d tool(s), %d skipped, %d new connection(s) created.',
            $dryRun ? '[dry-run] ' : '',
            $result['migrated'],
            $result['skipped'],
            $result['integrations_created'],
        ));

        return self::SUCCESS;
    }
}
