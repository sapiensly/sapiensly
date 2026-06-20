<?php

namespace App\Console\Commands;

use App\Models\McpAccessToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;

class MakeMcpTokenCommand extends Command
{
    protected $signature = 'mcp:token
        {user : The user id or email the token authenticates as}
        {--org= : The organization id or slug the token is bound to (defaults to the user active org)}
        {--name=Claude Code : A label for the token}
        {--abilities= : Comma-separated abilities (default: all)}';

    protected $description = 'Mint an organization-bound MCP access token for a user (prints the bearer token once).';

    public function handle(): int
    {
        $identifier = (string) $this->argument('user');
        $user = User::where('id', $identifier)->orWhere('email', $identifier)->first();

        if (! $user) {
            $this->error("No user found for '{$identifier}'.");

            return self::FAILURE;
        }

        $orgRef = (string) ($this->option('org') ?: $user->organization_id);
        $organization = Organization::where('id', $orgRef)->orWhere('slug', $orgRef)->first();

        if (! $organization) {
            $this->error('No organization found — pass --org=<id|slug> (the user has no active org).');

            return self::FAILURE;
        }

        if (! $user->isActiveMemberOf($organization->id)) {
            $this->error("{$user->email} is not an active member of {$organization->slug}.");

            return self::FAILURE;
        }

        $abilities = $this->option('abilities')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('abilities')))))
            : null;

        $plain = McpAccessToken::generateToken();

        McpAccessToken::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => (string) $this->option('name'),
            'token' => $plain,
            'abilities' => $abilities,
        ]);

        $this->info("MCP token for {$user->email} @ {$organization->slug} (".($abilities ? implode(', ', $abilities) : 'all abilities').'):');
        $this->line($plain);
        $this->newLine();
        $this->comment('Connect Claude Code with:');
        $this->line('  claude mcp add --transport http sapiensly '.url("mcp/{$organization->slug}/v1").' --header "Authorization: Bearer '.$plain.'"');

        return self::SUCCESS;
    }
}
