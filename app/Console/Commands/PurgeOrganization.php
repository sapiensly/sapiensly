<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Platform\OrganizationPurge;
use Illuminate\Console\Command;

/**
 * Honouring a deletion request.
 *
 * Deliberately NOT scheduled. Suspension is used for non-payment, for disputes
 * and for "we are not sure yet"; a timer that turned any of those into a purge
 * would destroy a paying customer's data because an invoice was late. Somebody
 * decides this, names the organization, and types its slug back.
 *
 * The two rails are the ones that matter: the organization has to be suspended
 * first, so there is always a reversible step before the irreversible one, and
 * the confirmation has to be the slug rather than a yes, so the wrong tenant
 * cannot be destroyed by muscle memory.
 */
class PurgeOrganization extends Command
{
    protected $signature = 'organizations:purge
        {organization : id or slug}
        {--confirm= : The organization slug, typed back}
        {--dry-run : Report what would go, delete nothing}';

    protected $description = 'Permanently destroy a suspended organization and everything belonging to it.';

    public function handle(OrganizationPurge $purge): int
    {
        $organization = Organization::withTrashed()
            ->where('id', $this->argument('organization'))
            ->orWhere('slug', $this->argument('organization'))
            ->first();

        if ($organization === null) {
            $this->error("No organization matches '{$this->argument('organization')}'.");

            return self::FAILURE;
        }

        $report = $purge->preview($organization);

        $this->line("Organization: {$organization->name} ({$organization->slug})");
        $this->line(sprintf('  %s row(s) across %d table(s), %s stored file(s).',
            number_format($report['rows']), count($report['tables']), number_format($report['files'])));
        $this->line('  Kept: '.implode(', ', $report['kept']).'.');

        if ($this->option('dry-run')) {
            foreach ($report['tables'] as $table => $count) {
                $this->line(sprintf('    %-45s %s', $table, number_format($count)));
            }

            return self::SUCCESS;
        }

        // Suspended first, always. A reversible step before the irreversible
        // one is the only thing that makes a mis-aimed purge survivable.
        if ($organization->deleted_at === null) {
            $this->error('Only a suspended organization can be purged. Suspend it first — that step is reversible, and this one is not.');

            return self::FAILURE;
        }

        if ($this->option('confirm') !== $organization->slug) {
            $this->error("Pass --confirm={$organization->slug} to destroy it. This cannot be undone.");

            return self::FAILURE;
        }

        $result = $purge->purge($organization);

        $this->info(sprintf(
            'Purged %s: %s row(s), %s file(s)%s.',
            $organization->slug,
            number_format($result['rows']),
            number_format($result['files']),
            $result['files_failed'] > 0 ? sprintf(', %d file(s) FAILED', $result['files_failed']) : '',
        ));

        if ($result['stuck'] !== []) {
            // Said out loud: rows left behind are exactly what somebody asking
            // for deletion needs to hear about.
            $this->warn('These tables could not be emptied and still hold rows: '.implode(', ', $result['stuck']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
