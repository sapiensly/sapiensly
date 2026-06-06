/**
 * Inertia prop contracts for the admin v2 pages. Canonical source: the
 * handoff spec at `handoff/data_contracts.md`. Controllers under
 * `app/Http/Controllers/Admin/*V2*` serialize Laravel models into these
 * shapes; Vue pages import the matching interface.
 *
 * Keep this file in sync with the controllers — if you add a prop in PHP,
 * add it here too.
 */

export type UUID = string;
export type ISODate = string;

// ─── shared ────────────────────────────────────────────────────────────
export interface AdminUser {
    id: number;
    name: string;
    email: string;
    emailVerifiedAt: ISODate | null;
    role: 'sysadmin' | 'admin' | 'owner' | 'member';
    status: 'active' | 'unverified' | 'blocked';
    twoFactorEnabled: boolean;
    lastSeenAt: ISODate | null;
    createdAt: ISODate;
    org?: { id: number; name: string } | null;
}

export interface HealthCheck {
    id: string; // 'db' | 'queue' | 'storage' | 'vector' | ...
    label: string;
    detail: string;
    status: 'ok' | 'warn' | 'error';
    lastCheckAt: ISODate;
}

export interface AuditEntry {
    id: UUID;
    /** Lucide icon name — maps to a component via auditIconMap(). */
    icon: string;
    actor: { id: number | null; name: string };
    action: string;
    target: string;
    targetHref?: string | null;
    context?: string | null;
    at: ISODate;
}

// ─── Dashboard ─────────────────────────────────────────────────────────
export interface DashboardStat {
    value: number;
    /** Formatted display string when the raw number needs custom units (e.g. "11.4s", "$24.81"). */
    display?: string;
    /** Secondary line under the value — "p95 2.1s", "$712 MTD", "124 organizations". */
    caption?: string;
    delta?: number;
    /** 'up' = good when green, 'down' = good when red (e.g. error rate). */
    deltaDir?: 'up' | 'down';
    series?: number[];
}

export interface DashboardLayer {
    count: number;
    subtitle: string;
    series: number[];
}

export interface DashboardProvider {
    name: string;
    calls: number;
    cost: number;
    /** One of the brand spectrum / accent tokens, referenced by CSS var name. */
    color: string;
}

export interface DashboardProps {
    stats: {
        ticketsResolved: DashboardStat;
        avgHandleTime: DashboardStat;
        tokensUsed: DashboardStat;
        spendToday: DashboardStat;
        totalUsers: DashboardStat;
    } | null;
    layers: {
        understand: DashboardLayer;
        discover: DashboardLayer;
        resolve: DashboardLayer;
    } | null;
    spend: {
        providers: DashboardProvider[];
    } | null;
    health: HealthCheck[];
    audit: AuditEntry[];
}

// ─── Users ─────────────────────────────────────────────────────────────
export interface UsersIndexProps {
    users: {
        data: AdminUser[];
        meta: {
            total: number;
            perPage: number;
            currentPage: number;
            lastPage: number;
        };
    };
    summary: {
        accountsTotal: number;
        organizationsTotal: number;
    };
    filters: {
        q?: string;
        role?: AdminUser['role'] | 'any';
        status?: AdminUser['status'] | 'any';
        sort?:
            | 'name'
            | '-name'
            | 'lastSeen'
            | '-lastSeen'
            | 'createdAt'
            | '-createdAt';
    };
}

// ─── Access Settings ───────────────────────────────────────────────────
export interface AccessProps {
    settings: {
        registrationOpen: boolean;
        emailVerificationRequired: boolean;
        twoFactorRequired: boolean;
        ipAllowlistEnabled: boolean;
        ipAllowlist: string[];
        domainAllowlist: string[];
        sessionLifetimeMinutes: number;
        concurrentSessionsMax: number | null;
    };
    posture: {
        id: string;
        label: string;
        ok: boolean;
        hint?: string;
        fixRoute?: string;
    }[];
}

// ─── Global AI ─────────────────────────────────────────────────────────
export type AiDriver =
    | 'anthropic'
    | 'openai'
    | 'gemini'
    | 'azure'
    | 'ollama'
    | 'mistral'
    | 'deepseek'
    | 'groq'
    | 'xai'
    | 'cohere'
    | 'voyageai'
    | 'jina'
    | 'eleven'
    | 'openrouter'
    | 'custom';
export type AiModelKind = 'chat' | 'embedding' | 'vision' | 'reasoning';

export type AiProviderKind = 'direct' | 'broker';

export interface AiProviderRow {
    driver: AiDriver;
    label: string;
    kind: AiProviderKind;
    credentialFields: string[];
    configured: boolean;
    /** Where the key comes from: a saved DB row, the .env config, or nothing. */
    source: 'db' | 'env' | null;
    masked: string | null;
    lastRotatedAt: ISODate | null;
    syncable: boolean;
    modelCount: number;
}

export interface AiModel {
    id: UUID;
    driver: AiDriver;
    name: string;
    kind: AiModelKind;
    providerKind: AiProviderKind;
    enabled: boolean;
    providerConfigured: boolean;
    contextWindow: number | null;
    inputPricePerMTok: number | null;
    outputPricePerMTok: number | null;
    registeredAt: ISODate;
}

export interface AiDefaultsProps {
    defaults: {
        primaryChatModelId: UUID | null;
        embeddingModelId: UUID | null;
        fallbackChatModelId: UUID | null;
        streaming: boolean;
        temperature: number;
        maxTokens: number;
    };
}

export interface AiCatalogProps {
    models: AiModel[];
}

export interface AiUsageProps {
    range: { from: ISODate; to: ISODate };
    totals: { chat: number; embeddings: number; cost: number };
    series: { date: ISODate; chat: number; embeddings: number }[];
    byDriver: { driver: AiDriver; calls: number; cost: number }[];
}

// ─── Global Cloud ──────────────────────────────────────────────────────
export interface CloudProps {
    storage: {
        driver: 's3' | 'r2' | 'gcs' | 'local';
        bucket: string;
        region: string;
        source: 'db' | 'env';
        usedBytes: number;
        totalBytes: number;
        lastBackupAt: ISODate | null;
    } | null;
    database: {
        engine: 'postgres' | 'mysql';
        version: string;
        host: string;
        sizeBytes: number;
        connections: { active: number; max: number };
        role: string;
        schemas: string[];
    };
    pgvector: {
        enabled: boolean;
        version: string | null;
        indexCount: number;
        vectorCount: number;
        sizeBytes: number;
        indexes: {
            name: string;
            schema: string;
            table: string;
            dim: number;
            metric: 'cosine' | 'l2' | 'ip';
            rows: number;
        }[];
    };
    tenancy: {
        schemas: {
            name: string;
            scope: 'control-plane' | 'tenant-data';
            tableCount: number;
            rls: boolean;
        }[];
        roles: {
            name: string;
            scope: 'owner' | 'platform' | 'tenant';
            present: boolean;
        }[];
        rls: {
            protected: number;
            tenantTables: number;
            expected: number;
        };
    } | null;
    redis: {
        reachable: boolean;
        version: string | null;
        mode: string | null;
        client: string;
        usedBytes: number | null;
        peakBytes: number | null;
        maxBytes: number | null;
        clients: number | null;
        uptimeSeconds: number | null;
        keys: number | null;
        roles: {
            key: 'cache' | 'queue' | 'session';
            active: boolean;
            connection: string;
            database: number;
        }[];
    };
}

// ─── Stack ─────────────────────────────────────────────────────────────
export interface StackProps {
    groups: {
        id: 'runtime' | 'frontend' | 'data' | 'ai' | 'infra';
        label: string;
        items: {
            name: string;
            version: string;
            description: string;
            status: 'ok' | 'outdated' | 'missing';
            docsUrl?: string;
        }[];
    }[];
}
