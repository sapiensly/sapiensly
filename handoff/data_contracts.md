# Inertia data contracts

Each admin page receives props from its Laravel controller. This file pins the TypeScript shape so the Vue SFCs and the controllers agree.

Put these in `resources/js/lib/admin/types.ts` and import per page.

```ts
// resources/js/lib/admin/types.ts

export type UUID = string
export type ISODate = string

// ─── shared ─────────────────────────────────────────────────────────
export interface AdminUser {
  id: number
  name: string
  email: string
  emailVerifiedAt: ISODate | null
  role: 'sysadmin' | 'admin' | 'owner' | 'member'
  status: 'active' | 'invited' | 'blocked'
  twoFactorEnabled: boolean
  lastSeenAt: ISODate | null
  createdAt: ISODate
  org?: { id: number; name: string }
}

export interface HealthCheck {
  id: string                 // 'db' | 'queue' | 'storage' | 'vector' | ...
  label: string
  detail: string
  status: 'ok' | 'warn' | 'error'
  lastCheckAt: ISODate
}

export interface AuditEntry {
  id: UUID
  actor: { id: number; name: string }
  action: string              // 'invited' | 'blocked' | 'rotated-key' | ...
  target: string              // human-readable target
  at: ISODate
}

// ─── Dashboard ──────────────────────────────────────────────────────
export interface DashboardProps {
  stats: {
    users:        { value: number; delta: number; series: number[] }
    activeToday:  { value: number; delta: number; series: number[] }
    aiCallsDay:   { value: number; delta: number; series: number[] }
    errorRate:    { value: number; delta: number; series: number[] }
  }
  layers: {
    understand: { count: number; subtitle: string }
    discover:   { count: number; subtitle: string }
    resolve:    { count: number; subtitle: string }
  }
  usage: {
    series: { date: ISODate; chat: number; embeddings: number; storage: number }[]
  }
  health: HealthCheck[]
  audit: AuditEntry[]          // last 10
}

// ─── Users ──────────────────────────────────────────────────────────
export interface UsersIndexProps {
  users: {
    data: AdminUser[]
    meta: { total: number; perPage: number; currentPage: number; lastPage: number }
  }
  filters: {
    q?: string
    role?: AdminUser['role'] | 'any'
    status?: AdminUser['status'] | 'any'
    sort?: 'name' | '-name' | 'lastSeen' | '-lastSeen' | 'createdAt' | '-createdAt'
  }
}

// ─── Access Settings ────────────────────────────────────────────────
export interface AccessProps {
  settings: {
    registrationOpen: boolean
    emailVerificationRequired: boolean
    twoFactorRequired: boolean
    ipAllowlistEnabled: boolean
    ipAllowlist: string[]                 // CIDR or IPs
    domainAllowlist: string[]             // e.g. ['acme.com', 'contractor.io']
    sessionLifetimeMinutes: number        // 15 .. 10080
    concurrentSessionsMax: number | null  // null = unlimited
  }
  posture: {
    id: string
    label: string
    ok: boolean
    hint?: string                         // shown if !ok
    fixRoute?: string                     // Ziggy route name for the "fix" button
  }[]
}

// ─── Global AI ──────────────────────────────────────────────────────
export type AiDriver = 'anthropic' | 'openai' | 'gemini' | 'azure' | 'ollama' | 'custom'
export type AiModelKind = 'chat' | 'embedding' | 'vision' | 'reasoning'

export interface AiModel {
  id: UUID
  driver: AiDriver
  name: string                  // 'claude-sonnet-4-5', 'text-embedding-3-small'
  kind: AiModelKind
  enabled: boolean
  contextWindow: number | null
  inputPricePerMTok: number | null
  outputPricePerMTok: number | null
  registeredAt: ISODate
}

export interface AiDefaultsProps {
  defaults: {
    primaryChatModelId: UUID | null
    embeddingModelId: UUID | null
    fallbackChatModelId: UUID | null
    streaming: boolean
    temperature: number         // 0..1
    maxTokens: number
  }
  keys: {
    driver: AiDriver
    label: string               // 'Primary Anthropic', 'OpenAI fallback'
    lastRotatedAt: ISODate | null
    lastUsedAt: ISODate | null
    masked: string              // 'sk-ant-...a1f3'
  }[]
}

export interface AiCatalogProps {
  models: AiModel[]
}

export interface AiUsageProps {
  range: { from: ISODate; to: ISODate }
  totals: { chat: number; embeddings: number; cost: number }
  series: { date: ISODate; chat: number; embeddings: number }[]
  byDriver: { driver: AiDriver; calls: number; cost: number }[]
}

// ─── Global Cloud ───────────────────────────────────────────────────
export interface CloudProps {
  storage: {
    driver: 's3' | 'r2' | 'gcs' | 'local'
    bucket: string
    region: string
    usedBytes: number
    totalBytes: number
    lastBackupAt: ISODate | null
  }
  database: {
    engine: 'postgres' | 'mysql'
    version: string
    host: string
    sizeBytes: number
    connections: { active: number; max: number }
  }
  pgvector: {
    enabled: boolean
    version: string | null
    indexCount: number
    vectorCount: number
    sizeBytes: number
    indexes: { name: string; table: string; dim: number; metric: 'cosine' | 'l2' | 'ip'; rows: number }[]
  }
}

// ─── Stack ──────────────────────────────────────────────────────────
export interface StackProps {
  groups: {
    id: 'runtime' | 'frontend' | 'data' | 'ai' | 'infra'
    label: string
    items: {
      name: string
      version: string
      description: string
      status: 'ok' | 'outdated' | 'missing'
      docsUrl?: string
    }[]
  }[]
}
```

## Controller responsibilities

- **Always** paginate `UserController@index` — the table expects a Laravel paginator shape.
- **Never** return full `AiModel` rows on `AiUsageProps` — use `byDriver` aggregates.
- **Mask** every secret before it leaves PHP. `keys[].masked` = `substr($key, 0, 8) . '…' . substr($key, -4)`.
- **Authorize** every page with a `SysadminMiddleware` (role === 'sysadmin') in addition to Laravel's built-in auth.
