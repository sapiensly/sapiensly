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
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ChevronRight,
    Folders,
    KeyRound,
    Lock,
    Pencil,
    Plug,
    Plus,
    Send,
    ShieldCheck,
    Star,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
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

interface Integration {
    id: string;
    name: string;
    description: string | null;
    base_url: string;
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

const props = defineProps<{ integration: Integration }>();

const { t } = useI18n();

const activeTab = ref<'requests' | 'environments' | 'settings'>('requests');

// OAuth2 Authorization-Code integrations need a browser roundtrip to get
// an access token. Detect the integration's current state so we can show
// the right banner: config-incomplete (missing client_id / endpoints),
// not-connected (config ok but handshake pending), or connected.
const needsOAuth2Authorization = computed(
    () => props.integration.auth_type === 'oauth2_auth_code',
);
const oauth2Connected = computed(
    () => !!props.integration.masked_auth_config?.access_token,
);
const oauth2ConfigIncomplete = computed(() => {
    if (!needsOAuth2Authorization.value) return false;
    const cfg = props.integration.masked_auth_config ?? {};
    // Non-secret fields pass through unmasked; secrets come back as "abcd...wxyz"
    // when present, undefined/empty when not. Missing any of these and GitHub
    // returns 404 on the authorize URL.
    return (
        !cfg.authorize_url ||
        !cfg.token_url ||
        !cfg.client_id ||
        !cfg.client_secret
    );
});
const authorizeUrl = computed(
    () => `/oauth/integrations/${props.integration.id}/authorize`,
);
const editUrl = computed(
    () => `/system/integrations/${props.integration.id}/edit`,
);

// Flash / validation errors from the authorize guard (e.g. "fields missing")
// come back on the Inertia page props.
const page = usePage();
const oauthFlashError = computed(() => {
    const errors = (page.props.errors ?? {}) as Record<string, string>;
    return errors.oauth2 ?? null;
});

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
    router.delete(`/system/integrations/requests/${requestId}`, { preserveScroll: true });
}

// ============== Environments ==============
const newEnvironment = useForm({
    name: '',
});

function createEnvironment(): void {
    newEnvironment.post(`/system/integrations/${props.integration.id}/environments`, {
        preserveScroll: true,
        onSuccess: () => newEnvironment.reset(),
    });
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
    router.delete(`/system/integrations/environments/${environmentId}`, { preserveScroll: true });
}

// ============== Variables (per-environment) ==============
const newVar = ref<Record<string, { key: string; value: string; is_secret: boolean; description: string }>>({});

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
    router.post(
        `/system/integrations/environments/${envId}/variables`,
        draft,
        {
            preserveScroll: true,
            onSuccess: () => {
                newVar.value[envId] = blankVariable();
            },
        },
    );
}

function deleteVariable(variableId: string): void {
    router.delete(`/system/integrations/variables/${variableId}`, { preserveScroll: true });
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
                                    class="inline-flex items-center rounded-pill border border-soft bg-white/5 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-ink-muted"
                                >
                                    {{ integration.auth_type }}
                                </span>
                                <span
                                    v-if="integration.last_test_status === 'success'"
                                    class="inline-flex items-center gap-1 rounded-pill border border-sp-success/30 bg-sp-success/10 px-2 py-0.5 text-[10px] font-medium text-sp-success"
                                >
                                    <CheckCircle2 class="size-3" />
                                    {{ t('system.integrations.test_status.success') }}
                                </span>
                                <span
                                    v-else-if="integration.last_test_status === 'failure'"
                                    class="inline-flex items-center gap-1 rounded-pill border border-sp-danger/30 bg-sp-danger/10 px-2 py-0.5 text-[10px] font-medium text-sp-danger"
                                >
                                    <XCircle class="size-3" />
                                    {{ t('system.integrations.test_status.failure') }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <Link :href="`/system/integrations/${integration.id}/executions`">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
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
                    OAuth 2.0 Authorization-Code browser handshake. Uses a
                    plain <a> (not Inertia <Link>) because /oauth/...
                    /authorize returns a 302 to the provider, outside the
                    SPA. The callback redirects back to this Show page.

                    Three states in priority order:
                      1. Flash/validation error from the authorize guard.
                      2. Config incomplete (missing client_id / endpoints).
                      3. Config ok, handshake pending.
                      4. Config ok, access token stored — "Connected".
                -->
                <div
                    v-if="oauthFlashError"
                    class="flex items-start gap-3 rounded-sp-sm border border-sp-danger/30 bg-sp-danger/10 p-4"
                >
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-sp-danger/15 text-sp-danger"
                    >
                        <AlertCircle class="size-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-ink">
                            {{ t('system.integrations.oauth2.error_title') }}
                        </p>
                        <p class="mt-0.5 text-xs text-ink-muted">
                            {{ oauthFlashError }}
                        </p>
                    </div>
                </div>

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
                    v-else-if="needsOAuth2Authorization && !oauth2Connected"
                    class="flex items-start gap-3 rounded-sp-sm border border-accent-blue/30 bg-accent-blue/10 p-4"
                >
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/15 text-accent-blue"
                    >
                        <KeyRound class="size-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-ink">
                            {{ t('system.integrations.oauth2.authorize_title') }}
                        </p>
                        <p class="mt-0.5 text-xs text-ink-muted">
                            {{ t('system.integrations.oauth2.authorize_hint') }}
                        </p>
                    </div>
                    <a
                        :href="authorizeUrl"
                        class="inline-flex shrink-0 items-center gap-1.5 self-center rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                    >
                        <KeyRound class="size-3.5" />
                        {{ t('system.integrations.oauth2.authorize_cta') }}
                    </a>
                </div>

                <div
                    v-else-if="needsOAuth2Authorization && oauth2Connected"
                    class="flex items-center gap-3 rounded-sp-sm border border-sp-success/30 bg-sp-success/10 p-4"
                >
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-sp-success/15 text-sp-success"
                    >
                        <ShieldCheck class="size-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-ink">
                            {{ t('system.integrations.oauth2.connected_title') }}
                        </p>
                        <p class="mt-0.5 text-xs text-ink-muted">
                            {{ t('system.integrations.oauth2.connected_hint') }}
                        </p>
                    </div>
                    <a
                        :href="authorizeUrl"
                        class="inline-flex shrink-0 items-center gap-1.5 self-center rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                    >
                        {{ t('system.integrations.oauth2.reauthorize_cta') }}
                    </a>
                </div>

                <Tabs v-model="activeTab">
                    <TabsList
                        class="h-auto w-fit gap-1 rounded-pill border border-soft bg-white/5 p-1 text-ink-muted"
                    >
                        <TabsTrigger
                            value="requests"
                            class="rounded-pill px-3.5 py-1.5 text-xs font-medium data-[state=active]:bg-accent-blue data-[state=active]:text-white data-[state=active]:shadow-btn-primary"
                        >
                            {{ t('system.integrations.tabs.requests') }}
                        </TabsTrigger>
                        <TabsTrigger
                            value="environments"
                            class="rounded-pill px-3.5 py-1.5 text-xs font-medium data-[state=active]:bg-accent-blue data-[state=active]:text-white data-[state=active]:shadow-btn-primary"
                        >
                            {{ t('system.integrations.tabs.environments') }}
                        </TabsTrigger>
                        <TabsTrigger
                            value="settings"
                            class="rounded-pill px-3.5 py-1.5 text-xs font-medium data-[state=active]:bg-accent-blue data-[state=active]:text-white data-[state=active]:shadow-btn-primary"
                        >
                            {{ t('system.integrations.tabs.settings') }}
                        </TabsTrigger>
                    </TabsList>

                    <!-- ============================ REQUESTS ============================ -->
                    <TabsContent value="requests" class="mt-4">
                        <div class="rounded-sp-sm border border-soft bg-navy">
                            <div class="flex items-center gap-3 border-b border-soft px-5 py-4">
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
                                        {{ t('system.integrations.show.requests_hint') }}
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
                                                v-for="m in ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']"
                                                :key="m"
                                                :value="m"
                                            >
                                                {{ m }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <Input
                                        v-model="newRequest.name"
                                        :placeholder="t('system.integrations.show.add_request')"
                                        class="h-9"
                                    />
                                    <Input
                                        v-model="newRequest.path"
                                        placeholder="/users/{id}"
                                        class="h-9"
                                    />
                                    <button
                                        type="submit"
                                        :disabled="newRequest.processing || !newRequest.name"
                                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                                    >
                                        <Plus class="size-3.5" />
                                        {{ t('system.integrations.show.add_request') }}
                                    </button>
                                </form>

                                <p
                                    v-if="integration.requests.length === 0"
                                    class="rounded-xs border border-dashed border-soft bg-white/[0.02] py-8 text-center text-xs text-ink-subtle"
                                >
                                    {{ t('system.integrations.show.no_requests') }}
                                </p>

                                <ul v-else class="divide-y divide-soft overflow-hidden rounded-xs border border-soft">
                                    <li
                                        v-for="req in integration.requests"
                                        :key="req.id"
                                        class="flex items-center gap-3 bg-white/[0.03] px-4 py-3 transition-colors hover:bg-white/[0.06]"
                                    >
                                        <span
                                            class="inline-flex w-16 shrink-0 justify-center rounded-pill border border-soft bg-white/5 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted"
                                        >
                                            {{ req.method }}
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-ink">{{ req.name }}</p>
                                            <p class="truncate text-xs text-ink-subtle">
                                                {{ req.path }}
                                            </p>
                                        </div>
                                        <Link
                                            :href="`/system/integrations/requests/${req.id}`"
                                            class="inline-flex size-8 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-white/5 hover:text-ink"
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
                    <TabsContent value="environments" class="mt-4">
                        <div class="rounded-sp-sm border border-soft bg-navy">
                            <div class="flex items-center gap-3 border-b border-soft px-5 py-4">
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
                                        {{ t('system.integrations.tabs.environments') }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ t('system.integrations.show.environments_hint') }}
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
                                        :placeholder="t('system.integrations.show.new_environment')"
                                        class="h-9 flex-1"
                                    />
                                    <button
                                        type="submit"
                                        :disabled="newEnvironment.processing || !newEnvironment.name"
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                                    >
                                        <Plus class="size-3.5" />
                                        {{ t('system.integrations.show.new_environment') }}
                                    </button>
                                </form>

                                <p
                                    v-if="integration.environments.length === 0"
                                    class="rounded-xs border border-dashed border-soft bg-white/[0.02] py-8 text-center text-xs text-ink-subtle"
                                >
                                    {{ t('system.integrations.show.no_environments') }}
                                </p>

                                <div v-else class="space-y-3">
                                    <div
                                        v-for="env in integration.environments"
                                        :key="env.id"
                                        class="space-y-3 rounded-xs border border-soft bg-white/[0.03] p-4"
                                    >
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-2">
                                                <p class="text-sm font-semibold text-ink">{{ env.name }}</p>
                                                <span
                                                    v-if="env.id === integration.active_environment_id"
                                                    class="inline-flex items-center gap-1 rounded-pill border border-sp-success/30 bg-sp-success/10 px-2 py-0.5 text-[10px] font-medium text-sp-success"
                                                >
                                                    <Star class="size-3" />
                                                    {{ t('system.integrations.show.active_environment') }}
                                                </span>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <button
                                                    v-if="env.id !== integration.active_environment_id"
                                                    type="button"
                                                    class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                                                    @click="activateEnvironment(env.id)"
                                                >
                                                    {{ t('system.integrations.show.activate') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="inline-flex size-8 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-sp-danger/10 hover:text-sp-danger"
                                                    @click="deleteEnvironment(env.id)"
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
                                                <code class="font-mono text-[11px] font-medium text-ink">{{ variable.key }}</code>
                                                <span class="flex-1 truncate text-ink-subtle">
                                                    {{ variable.value }}
                                                </span>
                                                <span
                                                    v-if="variable.is_secret"
                                                    class="inline-flex items-center gap-1 rounded-pill border border-soft bg-white/5 px-2 py-0.5 text-[10px] text-ink-muted"
                                                >
                                                    <Lock class="size-2.5" />
                                                    {{ t('system.integrations.show.variable_secret') }}
                                                </span>
                                                <button
                                                    type="button"
                                                    class="inline-flex size-7 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-sp-danger/10 hover:text-sp-danger"
                                                    @click="deleteVariable(variable.id)"
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
                                                :model-value="variableFor(env.id).key"
                                                :placeholder="t('system.integrations.show.variable_key')"
                                                class="h-8 text-xs"
                                                @update:model-value="variableFor(env.id).key = String($event)"
                                            />
                                            <Input
                                                :model-value="variableFor(env.id).value"
                                                :placeholder="t('system.integrations.show.variable_value')"
                                                class="h-8 text-xs"
                                                @update:model-value="variableFor(env.id).value = String($event)"
                                            />
                                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-xs border border-soft bg-white/[0.02] px-2.5 py-1.5 text-[11px] text-ink-muted transition-colors hover:border-medium hover:text-ink">
                                                <Checkbox
                                                    :model-value="variableFor(env.id).is_secret"
                                                    @update:model-value="variableFor(env.id).is_secret = $event === true"
                                                />
                                                <Lock class="size-3" />
                                                {{ t('system.integrations.show.variable_secret') }}
                                            </label>
                                            <button
                                                type="submit"
                                                class="inline-flex items-center gap-1 rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                                            >
                                                <Plus class="size-3.5" />
                                                {{ t('system.integrations.show.add_variable') }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </TabsContent>

                    <!-- ============================ SETTINGS ============================ -->
                    <TabsContent value="settings" class="mt-4 space-y-4">
                        <div class="rounded-sp-sm border border-soft bg-navy">
                            <div class="flex items-center gap-3 border-b border-soft px-5 py-4">
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
                                        {{ t('system.integrations.show.edit_config_title') }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ t('system.integrations.show.edit_config_hint') }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-3 px-5 py-4">
                                <p class="text-xs text-ink-muted">
                                    {{ t('system.integrations.show.edit_config_body') }}
                                </p>
                                <Link :href="`/system/integrations/${integration.id}/edit`">
                                    <button
                                        type="button"
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                                    >
                                        <Pencil class="size-3.5" />
                                        {{ t('common.edit') }}
                                    </button>
                                </Link>
                            </div>
                        </div>

                        <!-- Danger zone — red-tinted card to match the rest of the destructive patterns in admin-v2. -->
                        <div class="rounded-sp-sm border border-sp-danger/30 bg-navy">
                            <div class="flex items-center gap-3 border-b border-sp-danger/20 px-5 py-4">
                                <div
                                    class="flex size-8 items-center justify-center rounded-xs bg-sp-danger/15 text-sp-danger"
                                >
                                    <AlertTriangle class="size-4" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">
                                        {{ t('system.integrations.show.danger_title') }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ t('system.integrations.show.danger_hint') }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-3 px-5 py-4">
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
