<script setup lang="ts">
import InputError from '@/components/InputError.vue';
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
import type { HttpConnectionOption, RestApiConfig } from '@/types/tools';
import { ExternalLink, Info, Plug } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        config: RestApiConfig;
        errors: Record<string, string>;
        connections?: HttpConnectionOption[];
    }>(),
    { connections: () => [] },
);

const emit = defineEmits<{
    'update:config': [config: RestApiConfig];
}>();

const updateField = <K extends keyof RestApiConfig>(
    field: K,
    value: RestApiConfig[K],
) => {
    emit('update:config', {
        ...props.config,
        [field]: value,
    });
};

// A tool either borrows its base URL + auth from a Connection (the encouraged
// path) or carries them inline (legacy). This sentinel marks the inline choice
// in the connection picker since an empty Select value isn't allowed.
const INLINE = '__inline__';

const isConnected = computed(() => !!props.config.integration_id);

const connectionValue = computed({
    get: () => props.config.integration_id || INLINE,
    set: (value: string) => {
        if (value === INLINE) {
            // Switch to inline mode: drop the connection, restore inline defaults.
            const next = { ...props.config };
            delete next.integration_id;
            next.base_url = next.base_url ?? '';
            next.auth_type = next.auth_type ?? 'none';
            next.auth_config = next.auth_config ?? {};
            emit('update:config', next);
        } else {
            // Connected mode: reference the connection, drop the inline fields it
            // now supplies (base URL + auth).
            const next = { ...props.config, integration_id: value };
            delete next.base_url;
            delete next.auth_type;
            delete next.auth_config;
            emit('update:config', next);
        }
    },
});

const baseUrl = computed({
    get: () => props.config.base_url ?? '',
    set: (value: string) => updateField('base_url', value),
});

const method = computed({
    get: () => props.config.method ?? 'GET',
    set: (value: string) =>
        updateField('method', value as RestApiConfig['method']),
});

const path = computed({
    get: () => props.config.path ?? '',
    set: (value: string) => updateField('path', value),
});

const authType = computed({
    get: () => props.config.auth_type ?? 'none',
    set: (value: string) => {
        emit('update:config', {
            ...props.config,
            auth_type: value as RestApiConfig['auth_type'],
            auth_config: {},
        });
    },
});

const requestBodyTemplate = computed({
    get: () => props.config.request_body_template ?? '',
    set: (value: string) => updateField('request_body_template', value),
});

const methodOptions = [
    { value: 'GET', label: 'GET' },
    { value: 'POST', label: 'POST' },
    { value: 'PUT', label: 'PUT' },
    { value: 'PATCH', label: 'PATCH' },
    { value: 'DELETE', label: 'DELETE' },
];

const authOptions = [
    { value: 'none', label: t('tools.config.rest.auth_none') },
    { value: 'bearer', label: t('tools.config.rest.auth_bearer') },
    { value: 'api_key', label: t('tools.config.rest.auth_api_key') },
    { value: 'basic', label: t('tools.config.rest.auth_basic') },
    { value: 'oauth2', label: t('tools.config.rest.auth_oauth') },
];

const showRequestBody = computed(() =>
    ['POST', 'PUT', 'PATCH'].includes(method.value),
);
</script>

<template>
    <div class="space-y-4">
        <p
            class="flex items-start gap-2 rounded-xs border border-soft bg-white/[0.02] p-2.5 text-[11px] leading-snug text-ink-muted"
        >
            <Info class="mt-px size-3.5 shrink-0 text-ink-subtle" />
            <span>{{ t('tools.config.rest.guidance') }}</span>
        </p>

        <!-- Connection: the encouraged path. When set, base URL + auth come from
             the connection and their inline fields disappear. -->
        <div class="grid gap-2">
            <Label for="connection">{{
                t('tools.config.connection.label')
            }}</Label>
            <Select v-if="connections.length > 0" v-model="connectionValue">
                <SelectTrigger id="connection">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="c in connections"
                        :key="c.id"
                        :value="c.id"
                    >
                        {{ c.name }} — {{ c.base_url }}
                    </SelectItem>
                    <SelectItem :value="INLINE">
                        {{ t('tools.config.connection.inline') }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <div
                v-else
                class="flex items-center justify-between gap-3 rounded-xs border border-dashed border-soft p-3"
            >
                <p class="text-xs text-ink-muted">
                    {{ t('tools.config.connection.none') }}
                </p>
                <a
                    href="/system/integrations/create"
                    class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-accent-blue hover:underline"
                >
                    <ExternalLink class="size-3.5" />
                    {{ t('tools.config.connection.create') }}
                </a>
            </div>
            <p class="text-xs text-ink-muted">
                {{ t('tools.config.connection.hint') }}
            </p>
            <InputError :message="errors['config.integration_id']" />
        </div>

        <div
            v-if="isConnected"
            class="flex items-start gap-2 rounded-xs border border-dashed border-soft p-3"
        >
            <Plug class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
            <p class="text-xs text-ink-muted">
                {{ t('tools.config.connection.inherits') }}
            </p>
        </div>

        <div v-if="!isConnected" class="grid gap-2">
            <Label for="base-url">Base URL</Label>
            <Input
                id="base-url"
                v-model="baseUrl"
                type="url"
                placeholder="https://api.example.com"
                class="font-mono"
            />
            <p class="text-xs text-ink-muted">The base URL for the REST API</p>
            <InputError :message="errors['config.base_url']" />
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="grid gap-2">
                <Label for="method">HTTP Method</Label>
                <Select v-model="method">
                    <SelectTrigger id="method">
                        <SelectValue placeholder="Select method" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in methodOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="errors['config.method']" />
            </div>

            <div class="grid gap-2">
                <Label for="path">Path</Label>
                <Input
                    id="path"
                    v-model="path"
                    placeholder="/api/v1/resource/{id}"
                    class="font-mono"
                />
                <InputError :message="errors['config.path']" />
            </div>
        </div>

        <div v-if="!isConnected" class="grid gap-2">
            <Label for="auth-type">Authentication Type</Label>
            <Select v-model="authType">
                <SelectTrigger id="auth-type">
                    <SelectValue placeholder="Select auth type" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in authOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <p class="text-xs text-ink-muted">
                How to authenticate with the API
            </p>
            <InputError :message="errors['config.auth_type']" />
        </div>

        <div
            v-if="!isConnected && authType !== 'none'"
            class="rounded-xs border border-dashed border-soft p-4"
        >
            <p class="text-sm text-ink-muted">
                Authentication credentials will be configured securely after
                creating the tool.
            </p>
        </div>

        <div v-if="showRequestBody" class="grid gap-2">
            <Label for="request-body">Request Body Template</Label>
            <Textarea
                id="request-body"
                v-model="requestBodyTemplate"
                placeholder='{"key": "{{value}}", "param": "{{param}}"}'
                class="min-h-[100px] font-mono text-sm"
            />
            <p class="text-xs text-ink-muted">
                JSON template with
                <code v-pre class="rounded bg-white/[0.06] px-1">{{
                    variable
                }}</code>
                placeholders
            </p>
            <InputError :message="errors['config.request_body_template']" />
        </div>
    </div>
</template>
