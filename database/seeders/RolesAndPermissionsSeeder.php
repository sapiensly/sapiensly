<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Agents
            'agents.view',
            'agents.create',
            'agents.update',
            'agents.delete',

            // Agent Teams
            'agent-teams.view',
            'agent-teams.create',
            'agent-teams.update',
            'agent-teams.delete',

            // Knowledge Bases
            'knowledge-bases.view',
            'knowledge-bases.create',
            'knowledge-bases.update',
            'knowledge-bases.delete',

            // Documents
            'documents.view',
            'documents.create',
            'documents.update',
            'documents.delete',
            'documents.download',

            // Folders
            'folders.view',
            'folders.create',
            'folders.update',
            'folders.delete',

            // Chatbots
            'chatbots.view',
            'chatbots.create',
            'chatbots.update',
            'chatbots.delete',

            // Tools
            'tools.view',
            'tools.create',
            'tools.update',
            'tools.delete',

            // Flows
            'flows.view',
            'flows.create',
            'flows.update',
            'flows.delete',

            // AI Providers
            'ai-providers.view',
            'ai-providers.create',
            'ai-providers.update',
            'ai-providers.delete',

            // Organization
            'organization.manage',
            'organization.invite-members',
            'organization.remove-members',
            'organization.view-members',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // SysAdmin: global super admin (not team-scoped), has all permissions
        $sysAdminRole = Role::findOrCreate('sysadmin', 'web');
        $sysAdminRole->syncPermissions($permissions);

        // Owner: org-level admin, has all permissions within their organization
        $ownerRole = Role::findOrCreate('owner', 'web');
        $ownerRole->syncPermissions($permissions);

        // Member: full CRUD on resources, but no org management
        $memberRole = Role::findOrCreate('member', 'web');

        $memberPermissions = collect($permissions)->reject(fn (string $p): bool => in_array($p, [
            'organization.manage',
            'organization.invite-members',
            'organization.remove-members',
        ]))->values()->all();

        $memberRole->syncPermissions($memberPermissions);

        // Clean up old 'admin' role if it exists
        Role::where('name', 'admin')->delete();
    }
}
