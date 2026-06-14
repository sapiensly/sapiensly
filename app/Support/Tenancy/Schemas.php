<?php

namespace App\Support\Tenancy;

/**
 * Single source of truth for the platform/tenant schema split.
 *
 * A table is in the `tenant` schema (RLS-protected row data) iff it is listed
 * in {@see self::TENANT_TABLES}; everything else lives in `platform`
 * (control-plane definitions, accounts, providers, infra). Migrations qualify
 * table/FK names through {@see self::qualify()} and models pin their connection
 * through the same map (see the UsesTenantConnection / UsesPlatformConnection
 * traits), so the classification can never drift between schema and runtime.
 */
final class Schemas
{
    public const PLATFORM = 'platform';

    public const TENANT = 'tenant';

    public const PLATFORM_CONNECTION = 'platform';

    public const TENANT_CONNECTION = 'tenant';

    /**
     * Tables holding tenant row-data. Every such table carries a denormalized
     * tenant key (organization_id nullable + user_id) and an RLS policy, because
     * tenant_app cannot read platform parents to derive scope.
     *
     * @var list<string>
     */
    private const TENANT_TABLES = [
        // App-builder data
        'records',
        'record_relations',
        // Documents & RAG content
        'folders',
        'documents',
        'app_files',
        'knowledge_bases',
        'knowledge_base_documents',
        'document_knowledge_base',
        'knowledge_base_chunks',
        // Legacy agent conversations
        'conversations',
        'messages',
        // Chat module
        'chats',
        'chat_projects',
        'chat_messages',
        'chat_attachments',
        'chat_project_knowledge_bases',
        'chat_agents',
        // Widget / chatbot runtime
        'widget_sessions',
        'widget_conversations',
        'widget_messages',
        'chatbot_analytics',
        // Channels runtime
        'contacts',
        'whatsapp_conversations',
        'whatsapp_messages',
        // Integrations runtime
        'integration_executions',
        // Builder / workflow runtime
        'builder_conversations',
        'builder_messages',
        'workflow_runs',
        'workflow_step_runs',
        // Debates
        'debates',
        'debate_participants',
        'debate_rounds',
        'debate_turns',
    ];

    public static function isTenant(string $table): bool
    {
        return in_array(self::unqualify($table), self::TENANT_TABLES, true);
    }

    /**
     * The schema a table belongs to.
     */
    public static function schemaFor(string $table): string
    {
        return self::isTenant($table) ? self::TENANT : self::PLATFORM;
    }

    /**
     * The runtime connection a table's model should use.
     */
    public static function connectionFor(string $table): string
    {
        return self::isTenant($table) ? self::TENANT_CONNECTION : self::PLATFORM_CONNECTION;
    }

    /**
     * Schema-qualified table name, e.g. `tenant.records` / `platform.users`.
     * Idempotent: an already-qualified name is returned unchanged.
     */
    public static function qualify(string $table): string
    {
        if (str_contains($table, '.')) {
            return $table;
        }

        return self::schemaFor($table).'.'.$table;
    }

    /**
     * @return list<string>
     */
    public static function tenantTables(): array
    {
        return self::TENANT_TABLES;
    }

    private static function unqualify(string $table): string
    {
        $dot = strrpos($table, '.');

        return $dot === false ? $table : substr($table, $dot + 1);
    }
}
