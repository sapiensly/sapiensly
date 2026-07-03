<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ChevronRight,
    Database,
    Folders,
    Lock,
    Pencil,
    Plug,
    Plus,
    Send,
    Star,
    Trash2,
    Wrench,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface Variable {
    id: string;
    key: string;
    value: string;
    is_secret: boolean;
    description: string | null;
}

interface Environment {
    id: string;
    name: string;
    sort_order: number;
    variables: Variable[];
}

interface RequestRow {
    id: string;
    name: string;
    folder: string | null;
    method: string;
    path: string;
    sort_order: number;
}

interface LinkedTool {
    id: string;
    name: string;
    type: string;
    effect: string | null;
    status: string;
}

interface Integration {
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
    last_tested_at: string | null;
    last_test_status: string | null;
    last_test_message: string | null;
    allow_insecure_tls: boolean;
    active_environment_id: string | null;
    environments: Environment[];
    requests: RequestRow[];
    request_count: number;
    masked_auth_config: Record<string, unknown>;
}

const props = defineProps<{
    integration: Integration;
    linkedTools: LinkedTool[];
}>();

const { t } = useI18n();

// MCP and database connections aren't REST APIs — the HTTP request/environment
// builder doesn't apply. They're consumed by linking them in a tool, so we hide
// those tabs and open on the actions list instead.
const isMcp = computed(() => props.integration.is_mcp);
const isDatabase = computed(() => props.integration.kind === 'database');
const hidesHttpTabs = computed(() => isMcp.value || isDatabase.value);

const activeTab = ref<'requests' | 'environments' | 'actions' | 'settings'>(
    // MCP folds its linked tools into the hub card, so only Configuración
    // remains as a tab; a database still uses the actions tab.
    props.integration.is_mcp
        ? 'settings'
        : props.integration.kind === 'database'
          ? 'actions'
          : 'requests',
);

// OAuth2 Authorization-Code integrations need a browser roundtrip to get
// an access token. Detect the integration's current state so we can show
// the right banner: config-incomplete (missing client_id / endpoints),
// not-connected (config ok but handshake pending), or connected.
const needsOAuth2Authorization = computed(
    () => props.integration.auth_type === 'oauth2_auth_code',
);
const oauth2ConfigIncomplete = computed(() => {
    if (!needsOAuth2Authorization.value) return false;
    const cfg = props.integration.masked_auth_config ?? {};
    // Non-secret fields pass through unmasked; secrets come back as "abcd...wxyz"
    // when present, undefined/empty when not. Missing any of these and GitHub
    // returns 404 on the authorize URL.
    //
    // Public PKCE clients (the MCP default via dynamic registration) have no
    // client_secret by design, so don't demand one when PKCE is on.
    const requiresSecret = !cfg.pkce;
    return (
        !cfg.authorize_url ||
        !cfg.token_url ||
        !cfg.client_id ||
        (requiresSecret && !cfg.client_secret)
    );
});
const editUrl = computed(
    () => `/system/integrations/${props.integration.id}/edit`,
);

// ============== Requests ==============
const newRequest = useForm({
    name: '',
    method: 'GET',
    path: '/',
});

function createRequest(): void {
    newRequest.post(`/system/integrations/${props.integration.id}/requests`, {
        preserveScroll: true,
        onSuccess: () => newRequest.reset(),
    });
}

function deleteRequest(requestId: string): void {
    if (!confirm(t('common.confirm_delete'))) return;
    router.delete(`/system/integrations/requests/${requestId}`, {
        preserveScroll: true,
    });
}

// Promote a saved request into an agent-invocable tool on this connection.
function exposeAsTool(requestId: string): void {
    router.post(`/system/integrations/requests/${requestId}/expose-as-tool`);
}

// ============== Environments ==============
const newEnvironment = useForm({
    name: '',
});

function createEnvironment(): void {
    newEnvironment.post(
        `/system/integrations/${props.integration.id}/environments`,
        {
            preserveScroll: true,
            onSuccess: () => newEnvironment.reset(),
        },
    );
}

function activateEnvironment(environmentId: string): void {
    router.post(
        `/system/integrations/environments/${environmentId}/activate`,
        {},
        { preserveScroll: true },
    );
}

function deleteEnvironment(environmentId: string): void {
    if (!confirm(t('common.confirm_delete'))) return;
    router.delete(`/system/integrations/environments/${environmentId}`, {
        preserveScroll: true,
    });
}

// ============== Variables (per-environment) ==============
const newVar = ref<
    Record<
        string,
        { key: string; value: string; is_secret: boolean; description: string }
    >
>({});

function blankVariable() {
    return { key: '', value: '', is_secret: false, description: '' };
}

function variableFor(envId: string) {
    if (!newVar.value[envId]) {
        newVar.value[envId] = blankVariable();
    }
    return newVar.value[envId];
}

function addVariable(envId: string): void {
    const draft = newVar.value[envId];
    if (!draft || !draft.key) return;
    router.post(`/system/integrations/environments/${envId}/variables`, draft, {
        preserveScroll: true,
        onSuccess: () => {
            newVar.value[envId] = blankVariable();
        },
    });
}

function deleteVariable(variableId: string): void {
    router.delete(`/system/integrations/variables/${variableId}`, {
        preserveScroll: true,
    });
}

// ============== Danger zone ==============
function destroyIntegration(): void {
    if (!confirm(t('system.integrations.delete_confirm'))) return;
    router.delete(`/system/integrations/${props.integration.id}`);
}
</script>

<template>
    <Head :title="integration.name" />

    <AppLayoutV2 :title="t('app_v2.nav.integrations')">
        <div class="mx-auto max-w-5xl space-y-6">
            <!-- Back link to the integrations list — placed above the
                     header so the resource title keeps its visual weight. -->
            <Link
                href="/system/integrations"
                class="inline-flex items-center gap-1.5 text-xs text-ink-muted transition-colors hover:text-ink"
            >
                <ArrowLeft class="size-3.5" />
                {{ t('system.integrations.show.back') }}
            </Link>

            <div class="flex items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-xs bg-accent-blue/15 text-accent-blue"
                    >
                        <Plug class="size-5" />
                    </div>
                    <div class="min-w-0 space-y-1">
                        <PageHeader
                            :title="integration.name"
                            :description="integration.base_url"
                        />
                        <div class="flex flex-wrap gap-1.5 pt-1">
                            <span
                                class="inline-flex items-center rounded-pill border border-soft bg-surface px-2 py-0.5 text-[10px] font-medium tracking-wide text-ink-muted uppercase"
                            >
                                {{ integration.auth_type }}
                            </span>
                            <span
                                v-if="
                                    integration.last_test_status === 'success'
                                "
                                class="inline-flex items-center gap-1 rounded-pill border border-sp-success/30 bg-sp-success/10 px-2 py-0.5 text-[10px] font-medium text-sp-success"
                            >
                                <CheckCircle2 class="size-3" />
                                {{
                                    t('system.integrations.test_status.success')
                                }}
                            </span>
                            <span
                                v-else-if="
                                    integration.last_test_status === 'failure'
                                "
                                class="inline-flex items-center gap-1 rounded-pill border border-sp-danger/30 bg-sp-danger/10 px-2 py-0.5 text-[10px] font-medium text-sp-danger"
                            >
                                <XCircle class="size-3" />
                                {{
                                    t('system.integrations.test_status.failure')
                                }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <Link
                        :href="`/system/integrations/${integration.id}/executions`"
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                        >
                            {{ t('system.integrations.tabs.executions') }}
                        </button>
                    </Link>
                    <Link :href="`/system/integrations/${integration.id}/edit`">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Pencil class="size-3.5" />
                            {{ t('common.edit') }}
                        </button>
                    </Link>
                </div>
            </div>

            <!--
                    OAuth 2.0 client configuration status (org-level). The
                    per-user authorization (the browser handshake + tokens)
                    happens in Tools — never here — because integrations are
                    shared by the whole organization while authorization is
                    specific to each user.
                -->
            <div
                v-if="needsOAuth2Authorization && oauth2ConfigIncomplete"
                class="flex items-start gap-3 rounded-sp-sm border border-sp-warning/30 bg-sp-warning/10 p-4"
            >
                <div
                    class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-sp-warning/15 text-sp-warning"
                >
                    <AlertTriangle class="size-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-ink">
                        {{ t('system.integrations.oauth2.incomplete_title') }}
                    </p>
                    <p class="mt-0.5 text-xs text-ink-muted">
                        {{ t('system.integrations.oauth2.incomplete_hint') }}
                    </p>
                </div>
                <Link :href="editUrl">
                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 self-center rounded-pill bg-sp-warning px-3.5 py-1.5 text-xs font-medium text-navy-deep shadow-btn-primary transition-colors hover:brightness-110"
                    >
                        <Pencil class="size-3.5" />
                        {{ t('system.integrations.oauth2.incomplete_cta') }}
                    </button>
                </Link>
            </div>

            <div
                v-else-if="needsOAuth2Authorization && !isMcp"
                class="flex items-start gap-3 rounded-sp-sm border border-soft bg-surface p-4"
            >
                <div
                    class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/15 text-accent-blue"
                >
                    <CheckCircle2 class="size-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-ink">
                        {{ t('system.integrations.oauth2.client_ready_title') }}
                    </p>
                    <p class="mt-0.5 text-xs text-ink-muted">
                        {{ t('system.integrations.oauth2.client_ready_hint') }}
                    </p>
                </div>
            </div>

            <!-- MCP hub: an MCP server IS a tool provider — it exposes its
                     own operations over the protocol, so there are no REST
                     requests/actions to author. This one card is the whole
                     story: what it is, per-user OAuth, and the MCP tools bound
                     to it (the "actions" tab would just be the REST model). -->
            <div
                v-if="isMcp"
                class="overflow-hidden rounded-sp-sm border border-soft bg-navy"
            >
                <div class="flex items-start gap-3 border-b border-soft p-5">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/15 text-accent-blue"
                    >
                        <Plug class="size-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-medium text-ink">
                                {{ t('system.integrations.mcp.panel_title') }}
                            </p>
                            <span
                                v-if="
                                    needsOAuth2Authorization &&
                                    !oauth2ConfigIncomplete
                                "
                                class="inline-flex items-center gap-1 rounded-pill border border-accent-blue/30 bg-accent-blue/10 px-2 py-0.5 text-[10px] font-medium text-accent-blue"
                            >
                                <CheckCircle2 class="size-3" />
                                {{ t('system.integrations.mcp.oauth_ready') }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-ink-muted">
                            {{ t('system.integrations.mcp.panel_hint') }}
                        </p>
                    </div>
                    <a
                        href="/tools/create?type=mcp"
                        class="inline-flex shrink-0 items-center gap-1.5 self-center rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                    >
                        <Plus class="size-3.5" />
                        {{ t('system.integrations.mcp.create_tool') }}
                    </a>
                </div>

                <!-- MCP tools bound to this server (empty until you add one) -->
                <div class="p-5">
                    <p
                        class="mb-2 text-[11px] font-medium tracking-wider text-ink-subtle uppercase"
                    >
                        {{ t('system.integrations.mcp.tools_title') }}
                    </p>
                    <p
                        v-if="linkedTools.length === 0"
                        class="rounded-xs border border-dashed border-soft bg-white/[0.02] py-8 text-center text-xs text-ink-subtle"
                    >
                        {{ t('system.integrations.mcp.no_tools') }}
                    </p>
                    <ul
                        v-else
                        class="divide-y divide-soft overflow-hidden rounded-xs border border-soft"
                    >
                        <li
                            v-for="tool in linkedTools"
                            :key="tool.id"
                            class="flex items-center gap-3 bg-white/[0.03] px-4 py-3 transition-colors hover:bg-white/[0.06]"
                        >
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-xs bg-accent-blue/15 text-accent-blue"
                            >
                                <Plug class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate text-sm font-medium text-ink"
                                >
                                    {{ tool.name }}
                                </p>
                            </div>
                            <Link
                                :href="`/tools/${tool.id}`"
                                class="inline-flex size-8 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                            >
                                <ChevronRight class="size-4" />
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Database connections back agent query tools — no REST
                     builder here either. -->
            <div
                v-if="isDatabase"
                class="rounded-sp-sm border border-soft bg-navy p-5"
            >
                <div class="flex items-start gap-3">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-cyan/15 text-accent-cyan"
                    >
                        <Database class="size-4" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-ink">
                            {{ t('system.integrations.db.panel_title') }}
                        </p>
                        <p class="mt-0.5 text-xs text-ink-muted">
                            {{ t('system.integrations.db.panel_hint') }}
                        </p>
                        <a
                            href="/tools/create?type=database"
                            class="mt-3 inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Plus class="size-3.5" />
                            {{ t('system.integrations.db.create_tool') }}
                        </a>
                    </div>
                </div>
            </div>

            <Tabs v-model="activeTab">
                <TabsList
                    class="h-auto w-fit gap-1 rounded-pill border border-soft bg-surface p-1 text-ink-muted"
                >
                    <TabsTrigger
                        v-if="!hidesHttpTabs"
                        value="requests"
                        class="rounded-pill px-3.5 py-1.5 text-xs font-medium data-[state=active]:bg-accent-blue data-[state=active]:text-white data-[state=active]:shadow-btn-primary"
                    >
                        {{ t('system.integrations.tabs.requests') }}
                    </TabsTrigger>
                    <TabsTrigger
                        v-if="!hidesHttpTabs"
                        value="environments"
                        class="rounded-pill px-3.5 py-1.5 text-xs font-medium data-[state=active]:bg-accent-blue data-[state=active]:text-white data-[state=active]:shadow-btn-primary"
                    >
                        {{ t('system.integrations.tabs.environments') }}
                    </TabsTrigger>
                    <TabsTrigger
                        v-if="!isMcp"
                        value="actions"
                        class="rounded-pill px-3.5 py-1.5 text-xs font-medium data-[state=active]:bg-accent-blue data-[state=active]:text-white data-[state=active]:shadow-btn-primary"
                    >
                        {{ t('system.integrations.tabs.actions') }}
                    </TabsTrigger>
                    <TabsTrigger
                        value="settings"
                        class="rounded-pill px-3.5 py-1.5 text-xs font-medium data-[state=active]:bg-accent-blue data-[state=active]:text-white data-[state=active]:shadow-btn-primary"
                    >
                        {{ t('system.integrations.tabs.settings') }}
                    </TabsTrigger>
                </TabsList>

                <!-- ============================ REQUESTS ============================ -->
                <TabsContent
                    v-if="!hidesHttpTabs"
                    value="requests"
                    class="mt-4"
                >
                    <div class="rounded-sp-sm border border-soft bg-navy">
                        <div
                            class="flex items-center gap-3 border-b border-soft px-5 py-4"
                        >
                            <div
                                class="flex size-8 items-center justify-center rounded-xs"
                                :style="{
                                    backgroundColor: `color-mix(in oklab, var(--sp-accent-blue) 15%, transparent)`,
                                    color: 'var(--sp-accent-blue)',
                                }"
                            >
                                <Send class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">
                                    {{ t('system.integrations.tabs.requests') }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{
                                        t(
                                            'system.integrations.show.requests_hint',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-4 px-5 py-4">
                            <form
                                class="grid grid-cols-[auto_1fr_1fr_auto] items-center gap-2"
                                @submit.prevent="createRequest"
                            >
                                <Select v-model="newRequest.method">
                                    <SelectTrigger class="h-9 w-24">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="m in [
                                                'GET',
                                                'POST',
                                                'PUT',
                                                'PATCH',
                                                'DELETE',
                                            ]"
                                            :key="m"
                                            :value="m"
                                        >
                                            {{ m }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Input
                                    v-model="newRequest.name"
                                    :placeholder="
                                        t(
                                            'system.integrations.show.add_request',
                                        )
                                    "
                                    class="h-9"
                                />
                                <Input
                                    v-model="newRequest.path"
                                    placeholder="/users/{id}"
                                    class="h-9"
                                />
                                <button
                                    type="submit"
                                    :disabled="
                                        newRequest.processing ||
                                        !newRequest.name
                                    "
                                    class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                                >
                                    <Plus class="size-3.5" />
                                    {{
                                        t(
                                            'system.integrations.show.add_request',
                                        )
                                    }}
                                </button>
                            </form>

                            <p
                                v-if="integration.requests.length === 0"
                                class="rounded-xs border border-dashed border-soft bg-white/[0.02] py-8 text-center text-xs text-ink-subtle"
                            >
                                {{ t('system.integrations.show.no_requests') }}
                            </p>

                            <ul
                                v-else
                                class="divide-y divide-soft overflow-hidden rounded-xs border border-soft"
                            >
                                <li
                                    v-for="req in integration.requests"
                                    :key="req.id"
                                    class="flex items-center gap-3 bg-white/[0.03] px-4 py-3 transition-colors hover:bg-white/[0.06]"
                                >
                                    <span
                                        class="inline-flex w-16 shrink-0 justify-center rounded-pill border border-soft bg-surface px-2 py-0.5 text-[10px] font-semibold tracking-wide text-ink-muted uppercase"
                                    >
                                        {{ req.method }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="truncate text-sm font-medium text-ink"
                                        >
                                            {{ req.name }}
                                        </p>
                                        <p
                                            class="truncate text-xs text-ink-subtle"
                                        >
                                            {{ req.path }}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        :title="
                                            t(
                                                'system.integrations.show.expose_as_tool',
                                            )
                                        "
                                        class="inline-flex size-8 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                                        @click="exposeAsTool(req.id)"
                                    >
                                        <Wrench class="size-4" />
                                    </button>
                                    <Link
                                        :href="`/system/integrations/requests/${req.id}`"
                                        class="inline-flex size-8 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                                    >
                                        <ChevronRight class="size-4" />
                                    </Link>
                                    <button
                                        type="button"
                                        class="inline-flex size-8 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-sp-danger/10 hover:text-sp-danger"
                                        @click="deleteRequest(req.id)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </TabsContent>

                <!-- ============================ ENVIRONMENTS ============================ -->
                <TabsContent
                    v-if="!hidesHttpTabs"
                    value="environments"
                    class="mt-4"
                >
                    <div class="rounded-sp-sm border border-soft bg-navy">
                        <div
                            class="flex items-center gap-3 border-b border-soft px-5 py-4"
                        >
                            <div
                                class="flex size-8 items-center justify-center rounded-xs"
                                :style="{
                                    backgroundColor: `color-mix(in oklab, var(--sp-spectrum-magenta) 15%, transparent)`,
                                    color: 'var(--sp-spectrum-magenta)',
                                }"
                            >
                                <Folders class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">
                                    {{
                                        t(
                                            'system.integrations.tabs.environments',
                                        )
                                    }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{
                                        t(
                                            'system.integrations.show.environments_hint',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-4 px-5 py-4">
                            <form
                                class="flex items-center gap-2"
                                @submit.prevent="createEnvironment"
                            >
                                <Input
                                    v-model="newEnvironment.name"
                                    :placeholder="
                                        t(
                                            'system.integrations.show.new_environment',
                                        )
                                    "
                                    class="h-9 flex-1"
                                />
                                <button
                                    type="submit"
                                    :disabled="
                                        newEnvironment.processing ||
                                        !newEnvironment.name
                                    "
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                                >
                                    <Plus class="size-3.5" />
                                    {{
                                        t(
                                            'system.integrations.show.new_environment',
                                        )
                                    }}
                                </button>
                            </form>

                            <p
                                v-if="integration.environments.length === 0"
                                class="rounded-xs border border-dashed border-soft bg-white/[0.02] py-8 text-center text-xs text-ink-subtle"
                            >
                                {{
                                    t(
                                        'system.integrations.show.no_environments',
                                    )
                                }}
                            </p>

                            <div v-else class="space-y-3">
                                <div
                                    v-for="env in integration.environments"
                                    :key="env.id"
                                    class="space-y-3 rounded-xs border border-soft bg-white/[0.03] p-4"
                                >
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <div class="flex items-center gap-2">
                                            <p
                                                class="text-sm font-semibold text-ink"
                                            >
                                                {{ env.name }}
                                            </p>
                                            <span
                                                v-if="
                                                    env.id ===
                                                    integration.active_environment_id
                                                "
                                                class="inline-flex items-center gap-1 rounded-pill border border-sp-success/30 bg-sp-success/10 px-2 py-0.5 text-[10px] font-medium text-sp-success"
                                            >
                                                <Star class="size-3" />
                                                {{
                                                    t(
                                                        'system.integrations.show.active_environment',
                                                    )
                                                }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <button
                                                v-if="
                                                    env.id !==
                                                    integration.active_environment_id
                                                "
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                                                @click="
                                                    activateEnvironment(env.id)
                                                "
                                            >
                                                {{
                                                    t(
                                                        'system.integrations.show.activate',
                                                    )
                                                }}
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex size-8 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-sp-danger/10 hover:text-sp-danger"
                                                @click="
                                                    deleteEnvironment(env.id)
                                                "
                                            >
                                                <Trash2 class="size-4" />
                                            </button>
                                        </div>
                                    </div>

                                    <ul
                                        v-if="env.variables.length > 0"
                                        class="divide-y divide-soft overflow-hidden rounded-xs border border-soft bg-white/[0.02]"
                                    >
                                        <li
                                            v-for="variable in env.variables"
                                            :key="variable.id"
                                            class="flex items-center gap-2 px-3 py-2 text-xs"
                                        >
                                            <code
                                                class="font-mono text-[11px] font-medium text-ink"
                                                >{{ variable.key }}</code
                                            >
                                            <span
                                                class="flex-1 truncate text-ink-subtle"
                                            >
                                                {{ variable.value }}
                                            </span>
                                            <span
                                                v-if="variable.is_secret"
                                                class="inline-flex items-center gap-1 rounded-pill border border-soft bg-surface px-2 py-0.5 text-[10px] text-ink-muted"
                                            >
                                                <Lock class="size-2.5" />
                                                {{
                                                    t(
                                                        'system.integrations.show.variable_secret',
                                                    )
                                                }}
                                            </span>
                                            <button
                                                type="button"
                                                class="inline-flex size-7 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-sp-danger/10 hover:text-sp-danger"
                                                @click="
                                                    deleteVariable(variable.id)
                                                "
                                            >
                                                <Trash2 class="size-3" />
                                            </button>
                                        </li>
                                    </ul>

                                    <form
                                        class="grid grid-cols-[1fr_1fr_auto_auto] items-center gap-2"
                                        @submit.prevent="addVariable(env.id)"
                                    >
                                        <Input
                                            :model-value="
                                                variableFor(env.id).key
                                            "
                                            :placeholder="
                                                t(
                                                    'system.integrations.show.variable_key',
                                                )
                                            "
                                            class="h-8 text-xs"
                                            @update:model-value="
                                                variableFor(env.id).key =
                                                    String($event)
                                            "
                                        />
                                        <Input
                                            :model-value="
                                                variableFor(env.id).value
                                            "
                                            :placeholder="
                                                t(
                                                    'system.integrations.show.variable_value',
                                                )
                                            "
                                            class="h-8 text-xs"
                                            @update:model-value="
                                                variableFor(env.id).value =
                                                    String($event)
                                            "
                                        />
                                        <label
                                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-xs border border-soft bg-white/[0.02] px-2.5 py-1.5 text-[11px] text-ink-muted transition-colors hover:border-medium hover:text-ink"
                                        >
                                            <Checkbox
                                                :model-value="
                                                    variableFor(env.id)
                                                        .is_secret
                                                "
                                                @update:model-value="
                                                    variableFor(
                                                        env.id,
                                                    ).is_secret =
                                                        $event === true
                                                "
                                            />
                                            <Lock class="size-3" />
                                            {{
                                                t(
                                                    'system.integrations.show.variable_secret',
                                                )
                                            }}
                                        </label>
                                        <button
                                            type="submit"
                                            class="inline-flex items-center gap-1 rounded-pill border border-medium bg-surface px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                                        >
                                            <Plus class="size-3.5" />
                                            {{
                                                t(
                                                    'system.integrations.show.add_variable',
                                                )
                                            }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </TabsContent>

                <!-- ============================ ACTIONS (TOOLS) ============================ -->
                <!-- The integration is the connection; each linked tool is an
                         agent action that runs through it. This makes the
                         connection → action relationship visible from this side. -->
                <TabsContent v-if="!isMcp" value="actions" class="mt-4">
                    <div class="rounded-sp-sm border border-soft bg-navy">
                        <div
                            class="flex items-center gap-3 border-b border-soft px-5 py-4"
                        >
                            <div
                                class="flex size-8 items-center justify-center rounded-xs"
                                :style="{
                                    backgroundColor: `color-mix(in oklab, var(--sp-accent-cyan) 15%, transparent)`,
                                    color: 'var(--sp-accent-cyan)',
                                }"
                            >
                                <Wrench class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-ink">
                                    {{ t('system.integrations.tabs.actions') }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{
                                        t(
                                            'system.integrations.show.actions_hint',
                                        )
                                    }}
                                </p>
                            </div>
                            <a
                                :href="
                                    isMcp
                                        ? '/tools/create?type=mcp'
                                        : isDatabase
                                          ? '/tools/create?type=database'
                                          : '/tools/create'
                                "
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                            >
                                <Plus class="size-3.5" />
                                {{ t('system.integrations.show.new_action') }}
                            </a>
                        </div>

                        <div class="px-5 py-4">
                            <p
                                v-if="linkedTools.length === 0"
                                class="rounded-xs border border-dashed border-soft bg-white/[0.02] py-8 text-center text-xs text-ink-subtle"
                            >
                                {{ t('system.integrations.show.no_actions') }}
                            </p>

                            <ul
                                v-else
                                class="divide-y divide-soft overflow-hidden rounded-xs border border-soft"
                            >
                                <li
                                    v-for="action in linkedTools"
                                    :key="action.id"
                                    class="flex items-center gap-3 bg-white/[0.03] px-4 py-3 transition-colors hover:bg-white/[0.06]"
                                >
                                    <span
                                        class="inline-flex w-20 shrink-0 justify-center rounded-pill border border-soft bg-surface px-2 py-0.5 text-[10px] font-semibold tracking-wide text-ink-muted uppercase"
                                    >
                                        {{ action.type }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="truncate text-sm font-medium text-ink"
                                        >
                                            {{ action.name }}
                                        </p>
                                    </div>
                                    <span
                                        v-if="action.effect"
                                        class="inline-flex items-center rounded-pill border px-2 py-0.5 text-[10px] font-medium"
                                        :class="
                                            action.effect === 'write'
                                                ? 'border-sp-warning/30 bg-sp-warning/10 text-sp-warning'
                                                : 'border-soft bg-surface text-ink-muted'
                                        "
                                    >
                                        {{ action.effect }}
                                    </span>
                                    <Link
                                        :href="`/tools/${action.id}`"
                                        class="inline-flex size-8 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                                    >
                                        <ChevronRight class="size-4" />
                                    </Link>
                                </li>
                            </ul>
                        </div>
                    </div>
                </TabsContent>

                <!-- ============================ SETTINGS ============================ -->
                <TabsContent value="settings" class="mt-4 space-y-4">
                    <div class="rounded-sp-sm border border-soft bg-navy">
                        <div
                            class="flex items-center gap-3 border-b border-soft px-5 py-4"
                        >
                            <div
                                class="flex size-8 items-center justify-center rounded-xs"
                                :style="{
                                    backgroundColor: `color-mix(in oklab, var(--sp-accent-cyan) 15%, transparent)`,
                                    color: 'var(--sp-accent-cyan)',
                                }"
                            >
                                <Pencil class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">
                                    {{
                                        t(
                                            'system.integrations.show.edit_config_title',
                                        )
                                    }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{
                                        t(
                                            'system.integrations.show.edit_config_hint',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="flex items-center justify-between gap-3 px-5 py-4"
                        >
                            <p class="text-xs text-ink-muted">
                                {{
                                    t(
                                        'system.integrations.show.edit_config_body',
                                    )
                                }}
                            </p>
                            <Link
                                :href="`/system/integrations/${integration.id}/edit`"
                            >
                                <button
                                    type="button"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                                >
                                    <Pencil class="size-3.5" />
                                    {{ t('common.edit') }}
                                </button>
                            </Link>
                        </div>
                    </div>

                    <!-- Danger zone — red-tinted card to match the rest of the destructive patterns in admin-v2. -->
                    <div
                        class="rounded-sp-sm border border-sp-danger/30 bg-navy"
                    >
                        <div
                            class="flex items-center gap-3 border-b border-sp-danger/20 px-5 py-4"
                        >
                            <div
                                class="flex size-8 items-center justify-center rounded-xs bg-sp-danger/15 text-sp-danger"
                            >
                                <AlertTriangle class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">
                                    {{
                                        t(
                                            'system.integrations.show.danger_title',
                                        )
                                    }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{
                                        t(
                                            'system.integrations.show.danger_hint',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="flex items-center justify-between gap-3 px-5 py-4"
                        >
                            <p class="text-xs text-ink-muted">
                                {{ t('system.integrations.delete_confirm') }}
                            </p>
                            <button
                                type="button"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-pill border border-sp-danger/50 bg-sp-danger/10 px-3.5 py-1.5 text-xs font-medium text-sp-danger transition-colors hover:border-sp-danger hover:bg-sp-danger/20"
                                @click="destroyIntegration"
                            >
                                <Trash2 class="size-3.5" />
                                {{ t('common.delete') }}
                            </button>
                        </div>
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayoutV2>
</template>
