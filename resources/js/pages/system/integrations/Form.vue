<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import AuthConfigField from '@/components/integrations/AuthConfigField.vue';
import AuthMethodPicker from '@/components/integrations/AuthMethodPicker.vue';
import HeaderEditor from '@/components/integrations/HeaderEditor.vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Check,
    CheckCircle2,
    ChevronDown,
    Copy,
    Database,
    Eye,
    Globe,
    Heading1,
    Key,
    Loader2,
    Lock,
    Plug,
    Server,
    Sparkles,
    Ticket,
    UserCheck,
    Webhook,
    XCircle,
} from '@lucide/vue';
import axios from 'axios';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface AuthTypeOption {
    value: string;
    label: string;
}

interface IntegrationTemplate {
    name: string;
    description?: string;
    base_url: string;
    auth_type: string;
    default_headers: Array<{ key: string; value: string }>;
    auth_config?: Record<string, unknown>;
}

interface Props {
    mode: 'create' | 'edit';
    integration?: {
        id: string;
        name: string;
        description: string | null;
        base_url: string;
        is_mcp: boolean;
        kind: string;
        auth_type: string;
        visibility: string;
        status: string;
        color: string | null;
        icon: string | null;
        allow_insecure_tls: boolean;
        default_headers: Array<{ key: string; value: string }> | null;
        masked_auth_config: Record<string, unknown>;
    };
    authTypes: AuthTypeOption[];
    visibilities: AuthTypeOption[];
    template?: IntegrationTemplate | null;
    kind?: string | null;
    oauthCallbackUrl?: string;
    /** Inbound webhook receiver URL for this integration (edit mode only). */
    webhookUrl?: string;
    /** Inbound email receiver URL for this integration (edit mode only). */
    webhookEmailUrl?: string;
}

const props = defineProps<Props>();

const { t } = useI18n();

// When a template preset is provided on create, seed the form defaults
// from it. Blank credential-field shapes make the credential inputs
// render right away; for OAuth2 templates, the preset also carries the
// provider endpoints (authorize_url, token_url, scope, redirect_uri).
const creatingFromTemplate = props.mode === 'create' && !!props.template;
// Starting an MCP connection from the index grid: seed the web-auth flow MCP
// servers use, so the user lands ready to discover/authorize.
const startingAsMcp = props.mode === 'create' && props.kind === 'mcp';
const startingAsDatabase = props.mode === 'create' && props.kind === 'database';
const templateAuthConfigDefaults: Record<string, Record<string, unknown>> = {
    api_key: { location: 'header', name: '', value: '' },
    bearer: { token: '' },
    basic: { username: '', password: '' },
    custom_headers: { headers: [] },
    oauth2_client_credentials: {
        token_url: '',
        client_id: '',
        client_secret: '',
        scope: '',
        audience: '',
    },
    oauth2_auth_code: {
        authorize_url: '',
        token_url: '',
        client_id: '',
        client_secret: '',
        redirect_uri: '',
        scope: '',
        pkce: true,
    },
};

function buildInitialAuthConfig(): Record<string, unknown> {
    if (!creatingFromTemplate) return {};
    const shape = templateAuthConfigDefaults[props.template!.auth_type] ?? {};
    return { ...shape, ...(props.template!.auth_config ?? {}) };
}

const form = useForm({
    name: creatingFromTemplate
        ? (props.template!.name ?? '')
        : (props.integration?.name ?? ''),
    description: creatingFromTemplate
        ? (props.template!.description ?? '')
        : (props.integration?.description ?? ''),
    base_url: creatingFromTemplate
        ? (props.template!.base_url ?? '')
        : (props.integration?.base_url ?? ''),
    is_mcp: startingAsMcp ? true : (props.integration?.is_mcp ?? false),
    kind: startingAsDatabase
        ? 'database'
        : startingAsMcp
          ? 'mcp'
          : (props.integration?.kind ?? 'http'),
    auth_type: creatingFromTemplate
        ? (props.template!.auth_type ?? 'none')
        : startingAsMcp
          ? 'oauth2_auth_code'
          : (props.integration?.auth_type ?? 'none'),
    auth_config: startingAsDatabase
        ? {
              driver: 'pgsql',
              host: '',
              port: 5432,
              database: '',
              username: '',
              password: '',
          }
        : props.integration?.kind === 'database'
          ? // Editing a DB connection: the non-secret DSN fields come back
            // unmasked; secrets (password, SSH key) are blanked and kept on
            // save when left empty.
            {
                ...(props.integration.masked_auth_config ?? {}),
                password: '',
                ssh_private_key: '',
                ssh_passphrase: '',
                ssh_password: '',
            }
          : startingAsMcp
            ? { redirect_uri: props.oauthCallbackUrl ?? '', pkce: true }
            : buildInitialAuthConfig(),
    default_headers: creatingFromTemplate
        ? (props.template!.default_headers ?? [])
        : (props.integration?.default_headers ?? []),
    visibility: props.integration?.visibility ?? 'private',
    status: props.integration?.status ?? 'active',
    allow_insecure_tls: props.integration?.allow_insecure_tls ?? false,
});

// The creation experience is specialized per case: connecting an MCP server is
// a different job (discover → authorize) than wiring up a REST API (base URL +
// credentials + headers). One reactive flag drives which layout renders.
const isMcp = computed(() => form.is_mcp);
const isDatabase = computed(() => form.kind === 'database');

// Database connection fields live in auth_config (encrypted at rest).
function dbField(key: string) {
    return computed({
        get: () => (form.auth_config[key] as string | number | undefined) ?? '',
        set: (value: string | number) => {
            form.auth_config = { ...form.auth_config, [key]: value };
        },
    });
}
const dbDriver = dbField('driver');
const dbHost = dbField('host');
const dbPort = dbField('port');
const dbDatabase = dbField('database');
const dbUsername = dbField('username');
const dbPassword = dbField('password');

const dbDriverOptions = [
    { value: 'pgsql', label: 'PostgreSQL' },
    { value: 'mysql', label: 'MySQL' },
    { value: 'sqlsrv', label: 'SQL Server' },
    { value: 'sqlite', label: 'SQLite' },
];
const dbRequiresHost = computed(() => form.auth_config.driver !== 'sqlite');

// Optional SSH tunnel for bastion-only databases (flat ssh_* keys in
// auth_config so masking + edit-merge work like the password).
const useTunnel = ref(!!form.auth_config.ssh_host);
const sshHost = dbField('ssh_host');
const sshPort = dbField('ssh_port');
const sshUser = dbField('ssh_username');
const sshKey = dbField('ssh_private_key');
const sshPassphrase = dbField('ssh_passphrase');
const sshPassword = dbField('ssh_password');

const sshAuthMethod = ref<'key' | 'password'>(
    form.auth_config.ssh_password ? 'password' : 'key',
);

function setSshAuth(method: 'key' | 'password'): void {
    sshAuthMethod.value = method;
    const next = { ...form.auth_config };
    if (method === 'password') {
        delete next.ssh_private_key;
        delete next.ssh_passphrase;
    } else {
        delete next.ssh_password;
    }
    form.auth_config = next;
}

function toggleTunnel(on: boolean): void {
    useTunnel.value = on;
    if (!on) {
        const next = { ...form.auth_config };
        for (const k of [
            'ssh_host',
            'ssh_port',
            'ssh_username',
            'ssh_private_key',
            'ssh_passphrase',
            'ssh_password',
        ]) {
            delete next[k];
        }
        form.auth_config = next;
    } else if (!form.auth_config.ssh_port) {
        form.auth_config = { ...form.auth_config, ssh_port: 22 };
    }
}

// Keep base_url a readable DSN (no credentials) so lists and cards show the
// target at a glance — the executor reads the real fields from auth_config.
watch(
    () => form.auth_config,
    (cfg) => {
        if (form.kind !== 'database') return;
        const c = (cfg ?? {}) as Record<string, unknown>;
        const driver = (c.driver as string) || 'db';
        const host = (c.host as string) || '';
        const port = c.port ? `:${c.port}` : '';
        const database = (c.database as string) || '';
        form.base_url = `${driver}://${host}${port}/${database}`;
    },
    { deep: true, immediate: true },
);

const openBasics = ref(true);
const openAuth = ref(true);
const openHeaders = ref(false);
const openWebhook = ref(false);

const copiedWebhook = ref<'url' | 'email' | null>(null);
async function copyWebhook(text: string | undefined, which: 'url' | 'email') {
    if (!text) return;
    try {
        await navigator.clipboard.writeText(text);
        copiedWebhook.value = which;
        setTimeout(() => {
            copiedWebhook.value = null;
        }, 1500);
    } catch {
        // Clipboard API may be unavailable (HTTP/permission); no-op.
    }
}

/** Merge a single key into the auth_config form state (mirrors AuthConfigField). */
function setAuthConfig(key: string, value: string) {
    form.auth_config = { ...form.auth_config, [key]: value };
}

/** Placeholder that signals an existing secret is kept when left blank. */
function webhookSecretPlaceholder(): string {
    const masked = props.integration?.masked_auth_config?.webhook_secret;
    return typeof masked === 'string' && masked !== ''
        ? t('system.integrations.auth.kept_secret')
        : '';
}
const openVisibility = ref(false);

// MCP access: Public / OAuth (web auth) / Token cover the real cases; rarer
// schemes (api key, basic) hide behind a disclosure.
const mcpUsesPrimaryAuth = computed(() =>
    ['none', 'oauth2_auth_code', 'bearer'].includes(form.auth_type),
);
const showMoreMcpAuth = ref(!mcpUsesPrimaryAuth.value);

// An OAuth web-auth MCP server needs no manual fields when its client is
// registered (via Discover / dynamic registration); only servers without it
// need the endpoints + client id/secret, kept behind "advanced".
const oauthConfigured = computed(
    () => !!(form.auth_config.client_id || form.auth_config.authorize_url),
);
const showOAuthAdvanced = ref(false);

type TestState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'success'; message: string }
    | { status: 'error'; message: string };

const testState = ref<TestState>({ status: 'idle' });

async function testConnection(): Promise<void> {
    testState.value = { status: 'loading' };
    try {
        const { data } = await axios.post(
            '/system/integrations/test-connection',
            {
                base_url: form.base_url,
                kind: form.kind,
                auth_type: form.auth_type,
                auth_config: form.auth_config,
                allow_insecure_tls: form.allow_insecure_tls,
            },
        );

        testState.value = data.success
            ? {
                  status: 'success',
                  message:
                      data.message || t('system.integrations.test_success'),
              }
            : {
                  status: 'error',
                  message:
                      data.detail ||
                      data.message ||
                      t('system.integrations.test_failed'),
              };
    } catch {
        testState.value = {
            status: 'error',
            message: t('system.integrations.test_failed'),
        };
    }
}

// One-URL OAuth 2.0 auto-configuration (MCP discovery + dynamic registration).
// Discovery is an optional assist on the single Server URL field — there's no
// separate URL to paste.
type DiscoverState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'success'; message: string }
    | { status: 'error'; message: string };
const discoverState = ref<DiscoverState>({ status: 'idle' });

async function discoverOAuth2(): Promise<void> {
    if (!form.base_url) return;
    discoverState.value = { status: 'loading' };
    try {
        const { data } = await axios.post(
            '/system/integrations/oauth2/discover',
            { url: form.base_url, name: form.name || undefined },
        );

        form.base_url = data.base_url ?? form.base_url;
        if (!form.name) form.name = data.name ?? '';
        form.auth_type = 'oauth2_auth_code';
        form.auth_config = data.auth_config ?? {};
        if (data.is_mcp) form.is_mcp = true;

        discoverState.value = {
            status: 'success',
            message: data.dynamically_registered
                ? t('system.integrations.oauth2_discover.success_registered')
                : t(
                      'system.integrations.oauth2_discover.success_manual_client',
                  ),
        };
    } catch (error) {
        const message =
            axios.isAxiosError(error) && error.response?.data?.message
                ? (error.response.data.message as string)
                : t('system.integrations.oauth2_discover.failed');
        discoverState.value = { status: 'error', message };
    }
}

// Selecting OAuth (web) prefills the redirect URI with this app's callback —
// the value the user registers in their provider — so they never type it.
function selectAuthMethod(value: string): void {
    form.auth_type = value;
    if (
        value === 'oauth2_auth_code' &&
        !form.auth_config.redirect_uri &&
        props.oauthCallbackUrl
    ) {
        form.auth_config = {
            ...form.auth_config,
            redirect_uri: props.oauthCallbackUrl,
            pkce: form.auth_config.pkce ?? true,
        };
    }
}

function submit(): void {
    if (props.mode === 'create') {
        form.post('/system/integrations');
    } else if (props.integration) {
        form.put(`/system/integrations/${props.integration.id}`);
    }
}

// Shared section icon metadata so the Basics / Auth / Headers / Visibility
// cards carry the same tinted-tile header language as SettingsCard.
interface SectionMeta {
    icon: Component;
    tint: string;
}

const sectionMeta: Record<string, SectionMeta> = {
    basics: { icon: Plug, tint: 'var(--sp-accent-blue)' },
    auth: { icon: Key, tint: 'var(--sp-spectrum-magenta)' },
    headers: { icon: Heading1, tint: 'var(--sp-accent-cyan)' },
    visibility: { icon: Eye, tint: 'var(--sp-warning)' },
    webhook: { icon: Webhook, tint: 'var(--sp-spectrum-magenta)' },
};
</script>

<template>
    <Head :title="t('system.integrations.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.integrations')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="
                    mode === 'edit'
                        ? (integration?.name ?? '')
                        : isDatabase
                          ? t('system.integrations.form.db_title')
                          : isMcp
                            ? t('system.integrations.form.mcp_title')
                            : t('system.integrations.new')
                "
                :description="
                    isDatabase
                        ? t('system.integrations.form.db_description')
                        : isMcp
                          ? t('system.integrations.form.mcp_description')
                          : t('system.integrations.description')
                "
            />

            <form class="space-y-4" @submit.prevent="submit">
                <!-- ================= Database: external data source ================= -->
                <template v-if="isDatabase">
                    <!-- Basics. -->
                    <div class="rounded-sp-sm border border-soft bg-navy">
                        <div
                            class="flex items-center gap-3 border-b border-soft px-5 py-4"
                        >
                            <div
                                class="flex size-8 items-center justify-center rounded-xs bg-accent-cyan/15 text-accent-cyan"
                            >
                                <Database class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">
                                    {{ t('system.integrations.form.basics') }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{
                                        t(
                                            'system.integrations.form.db_basics_hint',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                        <div class="space-y-3 px-5 py-4">
                            <div class="space-y-1.5">
                                <Label for="db_name">{{
                                    t('system.integrations.form.name')
                                }}</Label>
                                <Input
                                    id="db_name"
                                    v-model="form.name"
                                    :placeholder="
                                        t(
                                            'system.integrations.form.name_placeholder',
                                        )
                                    "
                                    class="h-9"
                                />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="space-y-1.5">
                                <Label for="db_description">{{
                                    t('system.integrations.form.description')
                                }}</Label>
                                <Textarea
                                    id="db_description"
                                    v-model="form.description"
                                    rows="2"
                                />
                                <InputError
                                    :message="form.errors.description"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Connection. -->
                    <div class="rounded-sp-sm border border-soft bg-navy">
                        <div
                            class="flex items-center gap-3 border-b border-soft px-5 py-4"
                        >
                            <div
                                class="flex size-8 items-center justify-center rounded-xs bg-accent-cyan/15 text-accent-cyan"
                            >
                                <Key class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">
                                    {{
                                        t(
                                            'system.integrations.form.db_connection',
                                        )
                                    }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{
                                        t(
                                            'system.integrations.form.db_connection_hint',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                        <div class="space-y-3 px-5 py-4">
                            <div class="space-y-1.5">
                                <Label>{{
                                    t('system.integrations.form.db_driver')
                                }}</Label>
                                <Select v-model="dbDriver">
                                    <SelectTrigger class="h-9"
                                        ><SelectValue
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="o in dbDriverOptions"
                                            :key="o.value"
                                            :value="o.value"
                                        >
                                            {{ o.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div
                                v-if="dbRequiresHost"
                                class="grid grid-cols-[1fr_120px] gap-3"
                            >
                                <div class="space-y-1.5">
                                    <Label for="db_host">{{
                                        t('system.integrations.form.db_host')
                                    }}</Label>
                                    <Input
                                        id="db_host"
                                        v-model="dbHost"
                                        placeholder="db.example.com"
                                        class="h-9 font-mono"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <Label for="db_port">{{
                                        t('system.integrations.form.db_port')
                                    }}</Label>
                                    <Input
                                        id="db_port"
                                        v-model="dbPort"
                                        type="number"
                                        class="h-9"
                                    />
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <Label for="db_name_field">{{
                                    t('system.integrations.form.db_database')
                                }}</Label>
                                <Input
                                    id="db_name_field"
                                    v-model="dbDatabase"
                                    :placeholder="
                                        dbRequiresHost
                                            ? 'analytics'
                                            : '/path/to/db.sqlite'
                                    "
                                    class="h-9 font-mono"
                                />
                            </div>
                            <div
                                v-if="dbRequiresHost"
                                class="grid grid-cols-2 gap-3"
                            >
                                <div class="space-y-1.5">
                                    <Label for="db_user">{{
                                        t(
                                            'system.integrations.form.db_username',
                                        )
                                    }}</Label>
                                    <Input
                                        id="db_user"
                                        v-model="dbUsername"
                                        autocomplete="off"
                                        class="h-9"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <Label for="db_pass">{{
                                        t(
                                            'system.integrations.form.db_password',
                                        )
                                    }}</Label>
                                    <Input
                                        id="db_pass"
                                        v-model="dbPassword"
                                        type="password"
                                        autocomplete="new-password"
                                        :placeholder="
                                            mode === 'edit'
                                                ? t(
                                                      'system.integrations.auth.kept_secret',
                                                  )
                                                : ''
                                        "
                                        class="h-9"
                                    />
                                </div>
                            </div>

                            <!-- Optional SSH tunnel (bastion-only databases). -->
                            <label
                                for="db_use_tunnel"
                                class="flex cursor-pointer items-start gap-3 rounded-xs border border-soft bg-white/[0.03] p-3 transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
                            >
                                <Checkbox
                                    id="db_use_tunnel"
                                    :model-value="useTunnel"
                                    @update:model-value="
                                        toggleTunnel($event === true)
                                    "
                                />
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">
                                        {{
                                            t(
                                                'system.integrations.form.ssh_tunnel',
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-[11px] text-ink-subtle"
                                    >
                                        {{
                                            t(
                                                'system.integrations.form.ssh_tunnel_hint',
                                            )
                                        }}
                                    </p>
                                </div>
                            </label>

                            <div
                                v-if="useTunnel"
                                class="space-y-3 rounded-xs border border-soft bg-white/[0.02] p-3"
                            >
                                <div class="grid grid-cols-[1fr_120px] gap-3">
                                    <div class="space-y-1.5">
                                        <Label for="ssh_host">{{
                                            t(
                                                'system.integrations.form.ssh_host',
                                            )
                                        }}</Label>
                                        <Input
                                            id="ssh_host"
                                            v-model="sshHost"
                                            placeholder="bastion.example.com"
                                            class="h-9 font-mono"
                                        />
                                    </div>
                                    <div class="space-y-1.5">
                                        <Label for="ssh_port">{{
                                            t(
                                                'system.integrations.form.ssh_port',
                                            )
                                        }}</Label>
                                        <Input
                                            id="ssh_port"
                                            v-model="sshPort"
                                            type="number"
                                            placeholder="22"
                                            class="h-9"
                                        />
                                    </div>
                                </div>
                                <div class="space-y-1.5">
                                    <Label for="ssh_user">{{
                                        t('system.integrations.form.ssh_user')
                                    }}</Label>
                                    <Input
                                        id="ssh_user"
                                        v-model="sshUser"
                                        placeholder="jump"
                                        class="h-9"
                                        autocomplete="off"
                                    />
                                </div>

                                <!-- Tunnel auth: key (optionally passphrased) or password. -->
                                <div class="space-y-1.5">
                                    <Label>{{
                                        t('system.integrations.form.ssh_auth')
                                    }}</Label>
                                    <div
                                        class="inline-flex items-center gap-0.5 rounded-pill border border-soft bg-white/[0.03] p-0.5"
                                    >
                                        <button
                                            type="button"
                                            :class="[
                                                'rounded-pill px-2.5 py-1 text-[11px] font-medium transition-colors',
                                                sshAuthMethod === 'key'
                                                    ? 'bg-accent-blue text-white shadow-btn-primary'
                                                    : 'text-ink-muted hover:text-ink',
                                            ]"
                                            @click="setSshAuth('key')"
                                        >
                                            {{
                                                t(
                                                    'system.integrations.form.ssh_auth_key',
                                                )
                                            }}
                                        </button>
                                        <button
                                            type="button"
                                            :class="[
                                                'rounded-pill px-2.5 py-1 text-[11px] font-medium transition-colors',
                                                sshAuthMethod === 'password'
                                                    ? 'bg-accent-blue text-white shadow-btn-primary'
                                                    : 'text-ink-muted hover:text-ink',
                                            ]"
                                            @click="setSshAuth('password')"
                                        >
                                            {{
                                                t(
                                                    'system.integrations.form.ssh_auth_password',
                                                )
                                            }}
                                        </button>
                                    </div>
                                </div>

                                <template v-if="sshAuthMethod === 'key'">
                                    <div class="space-y-1.5">
                                        <Label for="ssh_key">{{
                                            t(
                                                'system.integrations.form.ssh_key',
                                            )
                                        }}</Label>
                                        <Textarea
                                            id="ssh_key"
                                            v-model="sshKey"
                                            :placeholder="
                                                mode === 'edit'
                                                    ? t(
                                                          'system.integrations.auth.kept_secret',
                                                      )
                                                    : '-----BEGIN OPENSSH PRIVATE KEY-----'
                                            "
                                            rows="4"
                                            class="font-mono text-xs"
                                        />
                                    </div>
                                    <div class="space-y-1.5">
                                        <Label for="ssh_passphrase">{{
                                            t(
                                                'system.integrations.form.ssh_passphrase',
                                            )
                                        }}</Label>
                                        <Input
                                            id="ssh_passphrase"
                                            v-model="sshPassphrase"
                                            type="password"
                                            autocomplete="new-password"
                                            :placeholder="
                                                mode === 'edit'
                                                    ? t(
                                                          'system.integrations.auth.kept_secret',
                                                      )
                                                    : ''
                                            "
                                            class="h-9"
                                        />
                                        <p class="text-[11px] text-ink-subtle">
                                            {{
                                                t(
                                                    'system.integrations.form.ssh_passphrase_hint',
                                                )
                                            }}
                                        </p>
                                    </div>
                                </template>

                                <div v-else class="space-y-1.5">
                                    <Label for="ssh_password">{{
                                        t(
                                            'system.integrations.form.ssh_password',
                                        )
                                    }}</Label>
                                    <Input
                                        id="ssh_password"
                                        v-model="sshPassword"
                                        type="password"
                                        autocomplete="new-password"
                                        :placeholder="
                                            mode === 'edit'
                                                ? t(
                                                      'system.integrations.auth.kept_secret',
                                                  )
                                                : ''
                                        "
                                        class="h-9"
                                    />
                                </div>

                                <p class="text-[11px] text-ink-subtle">
                                    {{
                                        t(
                                            'system.integrations.form.ssh_key_hint',
                                        )
                                    }}
                                </p>
                            </div>

                            <!-- Test: SELECT 1 against the DSN (through the tunnel if set). -->
                            <div class="flex flex-col gap-2 pt-1">
                                <button
                                    type="button"
                                    :disabled="
                                        testState.status === 'loading' ||
                                        !form.base_url
                                    "
                                    class="inline-flex items-center gap-1.5 self-start rounded-pill border border-medium bg-surface px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover disabled:opacity-50"
                                    @click="testConnection"
                                >
                                    <Loader2
                                        v-if="testState.status === 'loading'"
                                        class="size-3.5 animate-spin"
                                    />
                                    <Database v-else class="size-3.5" />
                                    {{
                                        testState.status === 'loading'
                                            ? t('system.integrations.testing')
                                            : t('system.integrations.test_now')
                                    }}
                                </button>
                                <div
                                    v-if="testState.status === 'success'"
                                    class="flex items-start gap-2 rounded-xs border border-sp-success/30 bg-sp-success/10 p-2 text-[11px] text-sp-success"
                                >
                                    <CheckCircle2
                                        class="mt-0.5 size-3.5 shrink-0"
                                    />
                                    <span>{{ testState.message }}</span>
                                </div>
                                <div
                                    v-else-if="testState.status === 'error'"
                                    class="flex items-start gap-2 rounded-xs border border-sp-danger/30 bg-sp-danger/10 p-2 text-[11px] text-sp-danger"
                                >
                                    <XCircle class="mt-0.5 size-3.5 shrink-0" />
                                    <span>{{ testState.message }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- ================= MCP server: discover → authorize ================= -->
                <template v-else-if="isMcp">
                    <!-- Basics: one Server URL field, with discovery as an inline assist. -->
                    <div class="rounded-sp-sm border border-soft bg-navy">
                        <div
                            class="flex items-center gap-3 border-b border-soft px-5 py-4"
                        >
                            <div
                                class="flex size-8 items-center justify-center rounded-xs bg-accent-blue/15 text-accent-blue"
                            >
                                <Server class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">
                                    {{ t('system.integrations.form.basics') }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{
                                        t(
                                            'system.integrations.form.mcp_basics_hint',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                        <div class="space-y-3 px-5 py-4">
                            <div class="space-y-1.5">
                                <Label for="mcp_name">
                                    {{ t('system.integrations.form.name') }}
                                </Label>
                                <Input
                                    id="mcp_name"
                                    v-model="form.name"
                                    :placeholder="
                                        t(
                                            'system.integrations.form.name_placeholder',
                                        )
                                    "
                                    class="h-9"
                                />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="space-y-1.5">
                                <Label for="mcp_url">
                                    {{
                                        t('system.integrations.form.server_url')
                                    }}
                                </Label>
                                <div class="flex gap-2">
                                    <Input
                                        id="mcp_url"
                                        v-model="form.base_url"
                                        :placeholder="
                                            t(
                                                'system.integrations.form.server_url_placeholder',
                                            )
                                        "
                                        class="h-9 font-mono"
                                        @keydown.enter.prevent="discoverOAuth2"
                                    />
                                    <button
                                        type="button"
                                        :disabled="
                                            discoverState.status ===
                                                'loading' || !form.base_url
                                        "
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-pill bg-accent-blue px-3 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                                        @click="discoverOAuth2"
                                    >
                                        <Loader2
                                            v-if="
                                                discoverState.status ===
                                                'loading'
                                            "
                                            class="size-3.5 animate-spin"
                                        />
                                        <Sparkles v-else class="size-3.5" />
                                        {{
                                            t(
                                                'system.integrations.oauth2_discover.action',
                                            )
                                        }}
                                    </button>
                                </div>
                                <p class="text-[11px] text-ink-subtle">
                                    {{
                                        t(
                                            'system.integrations.form.mcp_discover_hint',
                                        )
                                    }}
                                </p>
                                <div
                                    v-if="discoverState.status === 'success'"
                                    class="flex items-start gap-2 rounded-xs border border-sp-success/30 bg-sp-success/10 p-2 text-[11px] text-sp-success"
                                >
                                    <CheckCircle2
                                        class="mt-0.5 size-3.5 shrink-0"
                                    />
                                    <span>{{ discoverState.message }}</span>
                                </div>
                                <div
                                    v-else-if="discoverState.status === 'error'"
                                    class="flex items-start gap-2 rounded-xs border border-sp-danger/30 bg-sp-danger/10 p-2 text-[11px] text-sp-danger"
                                >
                                    <XCircle class="mt-0.5 size-3.5 shrink-0" />
                                    <span>{{ discoverState.message }}</span>
                                </div>
                                <InputError :message="form.errors.base_url" />
                            </div>
                        </div>
                    </div>

                    <!-- Access (auth). -->
                    <div class="rounded-sp-sm border border-soft bg-navy">
                        <div
                            class="flex items-center gap-3 border-b border-soft px-5 py-4"
                        >
                            <div
                                class="flex size-8 items-center justify-center rounded-xs"
                                :style="{
                                    backgroundColor: `color-mix(in oklab, ${sectionMeta.auth.tint} 15%, transparent)`,
                                    color: sectionMeta.auth.tint,
                                }"
                            >
                                <Key class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">
                                    {{
                                        t('system.integrations.mcp_auth.label')
                                    }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{ t('system.integrations.mcp_auth.hint') }}
                                </p>
                            </div>
                        </div>
                        <div class="space-y-3 px-5 py-4">
                            <div class="grid gap-2 sm:grid-cols-3">
                                <button
                                    type="button"
                                    :class="[
                                        'flex items-start gap-3 rounded-xs border p-3 text-left transition-colors',
                                        form.auth_type === 'none'
                                            ? 'border-accent-blue/50 bg-accent-blue/[0.08]'
                                            : 'border-soft bg-white/[0.03] hover:border-accent-blue/30 hover:bg-white/[0.06]',
                                    ]"
                                    @click="selectAuthMethod('none')"
                                >
                                    <Globe
                                        class="mt-0.5 size-4 shrink-0 text-ink-muted"
                                    />
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-ink">
                                            {{
                                                t(
                                                    'system.integrations.mcp_auth.public',
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="mt-0.5 text-[11px] text-ink-subtle"
                                        >
                                            {{
                                                t(
                                                    'system.integrations.mcp_auth.public_hint',
                                                )
                                            }}
                                        </p>
                                    </div>
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        'flex items-start gap-3 rounded-xs border p-3 text-left transition-colors',
                                        form.auth_type === 'oauth2_auth_code'
                                            ? 'border-accent-blue/50 bg-accent-blue/[0.08]'
                                            : 'border-soft bg-white/[0.03] hover:border-accent-blue/30 hover:bg-white/[0.06]',
                                    ]"
                                    @click="
                                        selectAuthMethod('oauth2_auth_code')
                                    "
                                >
                                    <UserCheck
                                        class="mt-0.5 size-4 shrink-0 text-ink-muted"
                                    />
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-ink">
                                            {{
                                                t(
                                                    'system.integrations.mcp_auth.oauth',
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="mt-0.5 text-[11px] text-ink-subtle"
                                        >
                                            {{
                                                t(
                                                    'system.integrations.mcp_auth.oauth_hint',
                                                )
                                            }}
                                        </p>
                                    </div>
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        'flex items-start gap-3 rounded-xs border p-3 text-left transition-colors',
                                        form.auth_type === 'bearer'
                                            ? 'border-accent-blue/50 bg-accent-blue/[0.08]'
                                            : 'border-soft bg-white/[0.03] hover:border-accent-blue/30 hover:bg-white/[0.06]',
                                    ]"
                                    @click="selectAuthMethod('bearer')"
                                >
                                    <Ticket
                                        class="mt-0.5 size-4 shrink-0 text-ink-muted"
                                    />
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-ink">
                                            {{
                                                t(
                                                    'system.integrations.mcp_auth.token',
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="mt-0.5 text-[11px] text-ink-subtle"
                                        >
                                            {{
                                                t(
                                                    'system.integrations.mcp_auth.token_hint',
                                                )
                                            }}
                                        </p>
                                    </div>
                                </button>
                            </div>

                            <!-- OAuth web auth: nothing to fill when the client is
                                 registered; advanced fields cover manual setup. -->
                            <template
                                v-if="form.auth_type === 'oauth2_auth_code'"
                            >
                                <p
                                    class="flex items-start gap-2 text-[11px] text-ink-subtle"
                                >
                                    <Lock class="mt-px size-3 shrink-0" />
                                    <span>{{
                                        t(
                                            'system.integrations.mcp_auth.per_user_note',
                                        )
                                    }}</span>
                                </p>
                                <p
                                    v-if="oauthConfigured"
                                    class="flex items-center gap-1.5 text-[11px] text-sp-success"
                                >
                                    <CheckCircle2 class="size-3.5 shrink-0" />
                                    {{
                                        t(
                                            'system.integrations.mcp_auth.oauth_configured',
                                        )
                                    }}
                                </p>
                                <p v-else class="text-[11px] text-ink-subtle">
                                    {{
                                        t(
                                            'system.integrations.mcp_auth.oauth_discover_hint',
                                        )
                                    }}
                                </p>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-[11px] font-medium text-accent-blue hover:underline"
                                    @click="
                                        showOAuthAdvanced = !showOAuthAdvanced
                                    "
                                >
                                    <ChevronDown
                                        :class="[
                                            'size-3 transition-transform',
                                            showOAuthAdvanced ||
                                            !oauthConfigured
                                                ? 'rotate-180'
                                                : '',
                                        ]"
                                    />
                                    {{
                                        t(
                                            'system.integrations.mcp_auth.advanced',
                                        )
                                    }}
                                </button>
                                <AuthConfigField
                                    v-if="showOAuthAdvanced || !oauthConfigured"
                                    :auth-type="form.auth_type"
                                    :model-value="form.auth_config"
                                    :masked-values="
                                        integration?.masked_auth_config
                                    "
                                    :callback-url="oauthCallbackUrl"
                                    :errors="form.errors"
                                    @update:model-value="
                                        form.auth_config = $event
                                    "
                                />
                            </template>

                            <!-- Public note, token field, or a picked api-key/basic. -->
                            <AuthConfigField
                                v-else
                                :auth-type="form.auth_type"
                                :model-value="form.auth_config"
                                :masked-values="integration?.masked_auth_config"
                                :callback-url="oauthCallbackUrl"
                                :errors="form.errors"
                                @update:model-value="form.auth_config = $event"
                            />

                            <!-- Rare schemes: API key, basic. -->
                            <button
                                type="button"
                                class="text-[11px] font-medium text-accent-blue hover:underline"
                                @click="showMoreMcpAuth = !showMoreMcpAuth"
                            >
                                {{ t('system.integrations.mcp_auth.more') }}
                            </button>
                            <div v-if="showMoreMcpAuth">
                                <AuthMethodPicker
                                    :options="authTypes"
                                    :model-value="form.auth_type"
                                    @update:model-value="selectAuthMethod"
                                />
                            </div>
                        </div>
                    </div>
                </template>

                <!-- ===================== REST / HTTP API ===================== -->
                <template v-else>
                    <!-- Basics. -->
                    <Collapsible
                        v-model:open="openBasics"
                        class="rounded-sp-sm border border-soft bg-navy"
                    >
                        <CollapsibleTrigger
                            class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex size-8 items-center justify-center rounded-xs"
                                    :style="{
                                        backgroundColor: `color-mix(in oklab, ${sectionMeta.basics.tint} 15%, transparent)`,
                                        color: sectionMeta.basics.tint,
                                    }"
                                >
                                    <component
                                        :is="sectionMeta.basics.icon"
                                        class="size-4"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">
                                        {{
                                            t('system.integrations.form.basics')
                                        }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{
                                            t(
                                                'system.integrations.form.basics_hint',
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                            <ChevronDown
                                :class="[
                                    openBasics ? 'rotate-180' : '',
                                    'size-4 shrink-0 text-ink-subtle transition-transform',
                                ]"
                            />
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div
                                class="space-y-3 border-t border-soft px-5 py-4"
                            >
                                <div class="space-y-1.5">
                                    <Label for="name">
                                        {{ t('system.integrations.form.name') }}
                                    </Label>
                                    <Input
                                        id="name"
                                        v-model="form.name"
                                        :placeholder="
                                            t(
                                                'system.integrations.form.name_placeholder',
                                            )
                                        "
                                        class="h-9"
                                    />
                                    <InputError :message="form.errors.name" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label for="description">
                                        {{
                                            t(
                                                'system.integrations.form.description',
                                            )
                                        }}
                                    </Label>
                                    <Textarea
                                        id="description"
                                        v-model="form.description"
                                        rows="2"
                                    />
                                    <InputError
                                        :message="form.errors.description"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <Label for="base_url">
                                        {{
                                            t(
                                                'system.integrations.form.base_url',
                                            )
                                        }}
                                    </Label>
                                    <Input
                                        id="base_url"
                                        v-model="form.base_url"
                                        :placeholder="
                                            t(
                                                'system.integrations.form.base_url_placeholder',
                                            )
                                        "
                                        class="h-9"
                                    />
                                    <InputError
                                        :message="form.errors.base_url"
                                    />
                                </div>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>

                    <!-- Authentication. -->
                    <Collapsible
                        v-model:open="openAuth"
                        class="rounded-sp-sm border border-soft bg-navy"
                    >
                        <CollapsibleTrigger
                            class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex size-8 items-center justify-center rounded-xs"
                                    :style="{
                                        backgroundColor: `color-mix(in oklab, ${sectionMeta.auth.tint} 15%, transparent)`,
                                        color: sectionMeta.auth.tint,
                                    }"
                                >
                                    <component
                                        :is="sectionMeta.auth.icon"
                                        class="size-4"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">
                                        {{
                                            t(
                                                'system.integrations.form.authentication',
                                            )
                                        }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{
                                            t(
                                                'system.integrations.form.authentication_hint',
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                            <ChevronDown
                                :class="[
                                    openAuth ? 'rotate-180' : '',
                                    'size-4 shrink-0 text-ink-subtle transition-transform',
                                ]"
                            />
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div
                                class="space-y-3 border-t border-soft px-5 py-4"
                            >
                                <div class="space-y-2">
                                    <Label>
                                        {{
                                            t(
                                                'system.integrations.form.auth_method',
                                            )
                                        }}
                                    </Label>
                                    <AuthMethodPicker
                                        :options="authTypes"
                                        :model-value="form.auth_type"
                                        @update:model-value="selectAuthMethod"
                                    />
                                </div>

                                <AuthConfigField
                                    :auth-type="form.auth_type"
                                    :model-value="form.auth_config"
                                    :masked-values="
                                        integration?.masked_auth_config
                                    "
                                    :callback-url="oauthCallbackUrl"
                                    :errors="form.errors"
                                    @update:model-value="
                                        form.auth_config = $event
                                    "
                                />

                                <!-- Test connection — pill trigger + semantic banners. -->
                                <div class="flex flex-col gap-2 pt-1">
                                    <button
                                        type="button"
                                        :disabled="
                                            testState.status === 'loading' ||
                                            !form.base_url
                                        "
                                        class="inline-flex items-center gap-1.5 self-start rounded-pill border border-medium bg-surface px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover disabled:opacity-50"
                                        @click="testConnection"
                                    >
                                        <Loader2
                                            v-if="
                                                testState.status === 'loading'
                                            "
                                            class="size-3.5 animate-spin"
                                        />
                                        <Plug v-else class="size-3.5" />
                                        {{
                                            testState.status === 'loading'
                                                ? t(
                                                      'system.integrations.testing',
                                                  )
                                                : t(
                                                      'system.integrations.test_now',
                                                  )
                                        }}
                                    </button>
                                    <div
                                        v-if="testState.status === 'success'"
                                        class="flex items-start gap-2 rounded-xs border border-sp-success/30 bg-sp-success/10 p-2 text-[11px] text-sp-success"
                                    >
                                        <CheckCircle2
                                            class="mt-0.5 size-3.5 shrink-0"
                                        />
                                        <span>{{ testState.message }}</span>
                                    </div>
                                    <div
                                        v-else-if="testState.status === 'error'"
                                        class="flex items-start gap-2 rounded-xs border border-sp-danger/30 bg-sp-danger/10 p-2 text-[11px] text-sp-danger"
                                    >
                                        <XCircle
                                            class="mt-0.5 size-3.5 shrink-0"
                                        />
                                        <span>{{ testState.message }}</span>
                                    </div>
                                </div>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>

                    <!-- Inbound webhook (integration.event trigger). -->
                    <Collapsible
                        v-model:open="openWebhook"
                        class="rounded-sp-sm border border-soft bg-navy"
                    >
                        <CollapsibleTrigger
                            class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex size-8 items-center justify-center rounded-xs"
                                    :style="{
                                        backgroundColor: `color-mix(in oklab, ${sectionMeta.webhook.tint} 15%, transparent)`,
                                        color: sectionMeta.webhook.tint,
                                    }"
                                >
                                    <component
                                        :is="sectionMeta.webhook.icon"
                                        class="size-4"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">
                                        {{
                                            t(
                                                'system.integrations.webhook.title',
                                            )
                                        }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{
                                            t(
                                                'system.integrations.webhook.hint',
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                            <ChevronDown
                                :class="[
                                    openWebhook ? 'rotate-180' : '',
                                    'size-4 shrink-0 text-ink-subtle transition-transform',
                                ]"
                            />
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div
                                class="space-y-3 border-t border-soft px-5 py-4"
                            >
                                <!-- Receiver URL (edit only) — give this to the provider. -->
                                <div
                                    v-if="mode === 'edit' && webhookUrl"
                                    class="space-y-1"
                                >
                                    <Label>{{
                                        t('system.integrations.webhook.url')
                                    }}</Label>
                                    <div class="flex items-center gap-2">
                                        <Input
                                            :model-value="webhookUrl"
                                            readonly
                                            class="font-mono text-xs"
                                        />
                                        <button
                                            type="button"
                                            class="inline-flex shrink-0 items-center gap-1 rounded-pill border border-medium bg-surface px-2.5 py-1 text-xs text-ink transition-colors hover:border-strong"
                                            @click="
                                                copyWebhook(webhookUrl, 'url')
                                            "
                                        >
                                            <Check
                                                v-if="copiedWebhook === 'url'"
                                                class="size-3.5 text-sp-success"
                                            />
                                            <Copy v-else class="size-3.5" />
                                            {{
                                                copiedWebhook === 'url'
                                                    ? t(
                                                          'system.integrations.webhook.copied',
                                                      )
                                                    : t(
                                                          'system.integrations.webhook.copy',
                                                      )
                                            }}
                                        </button>
                                    </div>
                                    <p class="text-xs text-ink-muted">
                                        {{
                                            t(
                                                'system.integrations.webhook.url_event_hint',
                                            )
                                        }}
                                    </p>
                                </div>

                                <!-- Email receiver URL (edit only) — for email.inbound. -->
                                <div
                                    v-if="mode === 'edit' && webhookEmailUrl"
                                    class="space-y-1"
                                >
                                    <Label>{{
                                        t(
                                            'system.integrations.webhook.email_url',
                                        )
                                    }}</Label>
                                    <div class="flex items-center gap-2">
                                        <Input
                                            :model-value="webhookEmailUrl"
                                            readonly
                                            class="font-mono text-xs"
                                        />
                                        <button
                                            type="button"
                                            class="inline-flex shrink-0 items-center gap-1 rounded-pill border border-medium bg-surface px-2.5 py-1 text-xs text-ink transition-colors hover:border-strong"
                                            @click="
                                                copyWebhook(
                                                    webhookEmailUrl,
                                                    'email',
                                                )
                                            "
                                        >
                                            <Check
                                                v-if="copiedWebhook === 'email'"
                                                class="size-3.5 text-sp-success"
                                            />
                                            <Copy v-else class="size-3.5" />
                                            {{
                                                copiedWebhook === 'email'
                                                    ? t(
                                                          'system.integrations.webhook.copied',
                                                      )
                                                    : t(
                                                          'system.integrations.webhook.copy',
                                                      )
                                            }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Email provider preset (for email.inbound normalization). -->
                                <div class="space-y-1">
                                    <Label>{{
                                        t(
                                            'system.integrations.webhook.email_provider',
                                        )
                                    }}</Label>
                                    <select
                                        :value="
                                            (form.auth_config
                                                .email_provider as string) ??
                                            'generic'
                                        "
                                        @change="
                                            setAuthConfig(
                                                'email_provider',
                                                (
                                                    $event.target as HTMLSelectElement
                                                ).value,
                                            )
                                        "
                                        class="h-9 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink"
                                    >
                                        <option value="generic">
                                            {{
                                                t(
                                                    'system.integrations.webhook.provider_generic',
                                                )
                                            }}
                                        </option>
                                        <option value="postmark">
                                            Postmark
                                        </option>
                                        <option value="mailgun">Mailgun</option>
                                        <option value="sendgrid">
                                            SendGrid
                                        </option>
                                    </select>
                                </div>

                                <div class="space-y-1">
                                    <Label>{{
                                        t('system.integrations.webhook.secret')
                                    }}</Label>
                                    <Input
                                        type="password"
                                        :model-value="
                                            (form.auth_config
                                                .webhook_secret as string) ?? ''
                                        "
                                        :placeholder="
                                            webhookSecretPlaceholder()
                                        "
                                        @update:model-value="
                                            setAuthConfig(
                                                'webhook_secret',
                                                $event,
                                            )
                                        "
                                    />
                                    <p class="text-xs text-ink-muted">
                                        {{
                                            t(
                                                'system.integrations.webhook.secret_hint',
                                            )
                                        }}
                                    </p>
                                </div>

                                <div class="space-y-1">
                                    <Label>{{
                                        t(
                                            'system.integrations.webhook.signature_header',
                                        )
                                    }}</Label>
                                    <Input
                                        :model-value="
                                            (form.auth_config
                                                .webhook_signature_header as string) ??
                                            ''
                                        "
                                        placeholder="X-Hub-Signature-256"
                                        @update:model-value="
                                            setAuthConfig(
                                                'webhook_signature_header',
                                                $event,
                                            )
                                        "
                                    />
                                </div>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>

                    <!-- Default headers. -->
                    <Collapsible
                        v-model:open="openHeaders"
                        class="rounded-sp-sm border border-soft bg-navy"
                    >
                        <CollapsibleTrigger
                            class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex size-8 items-center justify-center rounded-xs"
                                    :style="{
                                        backgroundColor: `color-mix(in oklab, ${sectionMeta.headers.tint} 15%, transparent)`,
                                        color: sectionMeta.headers.tint,
                                    }"
                                >
                                    <component
                                        :is="sectionMeta.headers.icon"
                                        class="size-4"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">
                                        {{
                                            t(
                                                'system.integrations.form.default_headers',
                                            )
                                        }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{
                                            t(
                                                'system.integrations.form.default_headers_hint',
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                            <ChevronDown
                                :class="[
                                    openHeaders ? 'rotate-180' : '',
                                    'size-4 shrink-0 text-ink-subtle transition-transform',
                                ]"
                            />
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div class="border-t border-soft px-5 py-4">
                                <HeaderEditor v-model="form.default_headers" />
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </template>

                <!-- ===================== Shared: visibility + advanced ===================== -->
                <Collapsible
                    v-model:open="openVisibility"
                    class="rounded-sp-sm border border-soft bg-navy"
                >
                    <CollapsibleTrigger
                        class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="flex size-8 items-center justify-center rounded-xs"
                                :style="{
                                    backgroundColor: `color-mix(in oklab, ${sectionMeta.visibility.tint} 15%, transparent)`,
                                    color: sectionMeta.visibility.tint,
                                }"
                            >
                                <component
                                    :is="sectionMeta.visibility.icon"
                                    class="size-4"
                                />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">
                                    {{
                                        t('system.integrations.form.visibility')
                                    }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{
                                        t(
                                            'system.integrations.form.visibility_hint',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                        <ChevronDown
                            :class="[
                                openVisibility ? 'rotate-180' : '',
                                'size-4 shrink-0 text-ink-subtle transition-transform',
                            ]"
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <div class="space-y-3 border-t border-soft px-5 py-4">
                            <div class="space-y-1.5">
                                <Select v-model="form.visibility">
                                    <SelectTrigger class="h-9">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in visibilities"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <label
                                for="allow_insecure_tls"
                                class="flex cursor-pointer items-start gap-3 rounded-xs border border-soft bg-white/[0.03] p-3 transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
                            >
                                <Checkbox
                                    id="allow_insecure_tls"
                                    :model-value="form.allow_insecure_tls"
                                    @update:model-value="
                                        form.allow_insecure_tls =
                                            $event === true
                                    "
                                />
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">
                                        {{
                                            t(
                                                'system.integrations.form.allow_insecure_tls',
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-[11px] text-ink-subtle"
                                    >
                                        {{
                                            t(
                                                'system.integrations.form.allow_insecure_tls_hint',
                                            )
                                        }}
                                    </p>
                                </div>
                            </label>
                        </div>
                    </CollapsibleContent>
                </Collapsible>

                <!-- Footer actions. -->
                <div class="flex items-center justify-end gap-2 pt-2">
                    <Link href="/system/integrations">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                        >
                            {{ t('system.integrations.form.cancel') }}
                        </button>
                    </Link>
                    <button
                        type="submit"
                        :disabled="
                            form.processing || !form.name || !form.base_url
                        "
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        {{ t('system.integrations.form.save') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
