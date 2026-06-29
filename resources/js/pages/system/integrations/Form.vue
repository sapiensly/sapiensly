<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AuthConfigField from '@/components/integrations/AuthConfigField.vue';
import AuthMethodPicker from '@/components/integrations/AuthMethodPicker.vue';
import HeaderEditor from '@/components/integrations/HeaderEditor.vue';
import InputError from '@/components/InputError.vue';
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
import axios from 'axios';
import {
    CheckCircle2,
    ChevronDown,
    Eye,
    Globe,
    Heading1,
    Key,
    Loader2,
    Lock,
    Plug,
    Server,
    Sparkles,
    UserCheck,
    XCircle,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
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
const templateAuthConfigDefaults: Record<string, Record<string, unknown>> = {
    api_key: { location: 'header', name: '', value: '' },
    bearer: { token: '' },
    basic: { username: '', password: '' },
    custom_headers: { headers: [] },
    oauth2_client_credentials: { token_url: '', client_id: '', client_secret: '', scope: '', audience: '' },
    oauth2_auth_code: { authorize_url: '', token_url: '', client_id: '', client_secret: '', redirect_uri: '', scope: '', pkce: true },
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
    auth_type: creatingFromTemplate
        ? (props.template!.auth_type ?? 'none')
        : startingAsMcp
          ? 'oauth2_auth_code'
          : (props.integration?.auth_type ?? 'none'),
    auth_config: startingAsMcp
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

const openBasics = ref(true);
const openAuth = ref(true);
const openHeaders = ref(false);
const openVisibility = ref(false);

// MCP access: Public / OAuth are the fast path; other token schemes hide behind
// a disclosure so they don't clutter the common case.
const mcpUsesPrimaryAuth = computed(() =>
    ['none', 'oauth2_auth_code'].includes(form.auth_type),
);
const showMoreMcpAuth = ref(!mcpUsesPrimaryAuth.value);

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
                : t('system.integrations.oauth2_discover.success_manual_client'),
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

function setMcpAuth(useOauth: boolean): void {
    selectAuthMethod(useOauth ? 'oauth2_auth_code' : 'none');
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
                        : isMcp
                          ? t('system.integrations.form.mcp_title')
                          : t('system.integrations.new')
                "
                :description="
                    isMcp
                        ? t('system.integrations.form.mcp_description')
                        : t('system.integrations.description')
                "
            />

            <form class="space-y-4" @submit.prevent="submit">
                <!-- ================= MCP server: discover → authorize ================= -->
                <template v-if="isMcp">
                    <!-- Basics: one Server URL field, with discovery as an inline assist. -->
                    <div class="rounded-sp-sm border border-soft bg-navy">
                        <div class="flex items-center gap-3 border-b border-soft px-5 py-4">
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
                                    {{ t('system.integrations.form.mcp_basics_hint') }}
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
                                    :placeholder="t('system.integrations.form.name_placeholder')"
                                    class="h-9"
                                />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="space-y-1.5">
                                <Label for="mcp_url">
                                    {{ t('system.integrations.form.server_url') }}
                                </Label>
                                <div class="flex gap-2">
                                    <Input
                                        id="mcp_url"
                                        v-model="form.base_url"
                                        :placeholder="t('system.integrations.form.server_url_placeholder')"
                                        class="h-9 font-mono"
                                        @keydown.enter.prevent="discoverOAuth2"
                                    />
                                    <button
                                        type="button"
                                        :disabled="discoverState.status === 'loading' || !form.base_url"
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-pill bg-accent-blue px-3 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                                        @click="discoverOAuth2"
                                    >
                                        <Loader2
                                            v-if="discoverState.status === 'loading'"
                                            class="size-3.5 animate-spin"
                                        />
                                        <Sparkles v-else class="size-3.5" />
                                        {{ t('system.integrations.oauth2_discover.action') }}
                                    </button>
                                </div>
                                <p class="text-[11px] text-ink-subtle">
                                    {{ t('system.integrations.form.mcp_discover_hint') }}
                                </p>
                                <div
                                    v-if="discoverState.status === 'success'"
                                    class="flex items-start gap-2 rounded-xs border border-sp-success/30 bg-sp-success/10 p-2 text-[11px] text-sp-success"
                                >
                                    <CheckCircle2 class="mt-0.5 size-3.5 shrink-0" />
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
                        <div class="flex items-center gap-3 border-b border-soft px-5 py-4">
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
                                    {{ t('system.integrations.mcp_auth.label') }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{ t('system.integrations.mcp_auth.hint') }}
                                </p>
                            </div>
                        </div>
                        <div class="space-y-3 px-5 py-4">
                            <div class="grid gap-2 sm:grid-cols-2">
                                <button
                                    type="button"
                                    :class="[
                                        'flex items-start gap-3 rounded-xs border p-3 text-left transition-colors',
                                        form.auth_type === 'none'
                                            ? 'border-accent-blue/50 bg-accent-blue/[0.08]'
                                            : 'border-soft bg-white/[0.03] hover:border-accent-blue/30 hover:bg-white/[0.06]',
                                    ]"
                                    @click="setMcpAuth(false)"
                                >
                                    <Globe class="mt-0.5 size-4 shrink-0 text-ink-muted" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-ink">
                                            {{ t('system.integrations.mcp_auth.public') }}
                                        </p>
                                        <p class="mt-0.5 text-[11px] text-ink-subtle">
                                            {{ t('system.integrations.mcp_auth.public_hint') }}
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
                                    @click="setMcpAuth(true)"
                                >
                                    <UserCheck class="mt-0.5 size-4 shrink-0 text-ink-muted" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-ink">
                                            {{ t('system.integrations.mcp_auth.oauth') }}
                                        </p>
                                        <p class="mt-0.5 text-[11px] text-ink-subtle">
                                            {{ t('system.integrations.mcp_auth.oauth_hint') }}
                                        </p>
                                    </div>
                                </button>
                            </div>

                            <p
                                v-if="form.auth_type === 'oauth2_auth_code'"
                                class="flex items-start gap-2 text-[11px] text-ink-subtle"
                            >
                                <Lock class="mt-px size-3 shrink-0" />
                                <span>{{ t('system.integrations.mcp_auth.per_user_note') }}</span>
                            </p>

                            <!-- Escape hatch: token / api-key MCP servers. -->
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

                            <AuthConfigField
                                :auth-type="form.auth_type"
                                :model-value="form.auth_config"
                                :masked-values="integration?.masked_auth_config"
                                :callback-url="oauthCallbackUrl"
                                :errors="form.errors"
                                @update:model-value="form.auth_config = $event"
                            />
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
                                    <component :is="sectionMeta.basics.icon" class="size-4" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">
                                        {{ t('system.integrations.form.basics') }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ t('system.integrations.form.basics_hint') }}
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
                            <div class="space-y-3 border-t border-soft px-5 py-4">
                                <div class="space-y-1.5">
                                    <Label for="name">
                                        {{ t('system.integrations.form.name') }}
                                    </Label>
                                    <Input
                                        id="name"
                                        v-model="form.name"
                                        :placeholder="t('system.integrations.form.name_placeholder')"
                                        class="h-9"
                                    />
                                    <InputError :message="form.errors.name" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label for="description">
                                        {{ t('system.integrations.form.description') }}
                                    </Label>
                                    <Textarea
                                        id="description"
                                        v-model="form.description"
                                        rows="2"
                                    />
                                    <InputError :message="form.errors.description" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label for="base_url">
                                        {{ t('system.integrations.form.base_url') }}
                                    </Label>
                                    <Input
                                        id="base_url"
                                        v-model="form.base_url"
                                        :placeholder="t('system.integrations.form.base_url_placeholder')"
                                        class="h-9"
                                    />
                                    <InputError :message="form.errors.base_url" />
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
                                    <component :is="sectionMeta.auth.icon" class="size-4" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">
                                        {{ t('system.integrations.form.authentication') }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ t('system.integrations.form.authentication_hint') }}
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
                            <div class="space-y-3 border-t border-soft px-5 py-4">
                                <div class="space-y-2">
                                    <Label>
                                        {{ t('system.integrations.form.auth_method') }}
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
                                    :masked-values="integration?.masked_auth_config"
                                    :callback-url="oauthCallbackUrl"
                                    :errors="form.errors"
                                    @update:model-value="form.auth_config = $event"
                                />

                                <!-- Test connection — pill trigger + semantic banners. -->
                                <div class="flex flex-col gap-2 pt-1">
                                    <button
                                        type="button"
                                        :disabled="testState.status === 'loading' || !form.base_url"
                                        class="inline-flex self-start items-center gap-1.5 rounded-pill border border-medium bg-surface px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover disabled:opacity-50"
                                        @click="testConnection"
                                    >
                                        <Loader2
                                            v-if="testState.status === 'loading'"
                                            class="size-3.5 animate-spin"
                                        />
                                        <Plug v-else class="size-3.5" />
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
                                        <CheckCircle2 class="mt-0.5 size-3.5 shrink-0" />
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
                                    <component :is="sectionMeta.headers.icon" class="size-4" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">
                                        {{ t('system.integrations.form.default_headers') }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ t('system.integrations.form.default_headers_hint') }}
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
                                <component :is="sectionMeta.visibility.icon" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">
                                    {{ t('system.integrations.form.visibility') }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{ t('system.integrations.form.visibility_hint') }}
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
                                    @update:model-value="form.allow_insecure_tls = $event === true"
                                />
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">
                                        {{ t('system.integrations.form.allow_insecure_tls') }}
                                    </p>
                                    <p class="mt-0.5 text-[11px] text-ink-subtle">
                                        {{ t('system.integrations.form.allow_insecure_tls_hint') }}
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
                        :disabled="form.processing || !form.name || !form.base_url"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        {{ t('system.integrations.form.save') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
