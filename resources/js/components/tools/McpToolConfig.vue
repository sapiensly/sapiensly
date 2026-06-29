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
import type { McpConfig, OAuth2IntegrationOption } from '@/types/tools';
import { CheckCircle2, ExternalLink, Info } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        config: McpConfig;
        errors: Record<string, string>;
        oauth2Integrations?: OAuth2IntegrationOption[];
        // Per-user authorize URL for the saved tool. Absent on create (the
        // tool must be saved before it can be authorized).
        oauth2AuthorizeUrl?: string | null;
    }>(),
    { oauth2Integrations: () => [], oauth2AuthorizeUrl: null },
);

const emit = defineEmits<{
    'update:config': [config: McpConfig];
}>();

const endpoint = computed({
    get: () => props.config.endpoint ?? '',
    set: (value: string) => {
        emit('update:config', {
            ...props.config,
            endpoint: value,
        });
    },
});

const authType = computed({
    get: () => props.config.auth_type ?? 'none',
    set: (value: string) => {
        emit('update:config', {
            ...props.config,
            auth_type: value as McpConfig['auth_type'],
            auth_config: {},
            // The integration link only applies to the oauth2 scheme.
            integration_id: value === 'oauth2' ? props.config.integration_id : undefined,
        });
    },
});

const integrationId = computed({
    get: () => props.config.integration_id ?? '',
    set: (value: string) => {
        emit('update:config', {
            ...props.config,
            integration_id: value,
        });
    },
});

const authOptions = [
    { value: 'none', label: t('tools.config.mcp.auth_none') },
    { value: 'bearer', label: t('tools.config.mcp.auth_bearer') },
    { value: 'api_key', label: t('tools.config.mcp.auth_api_key') },
    { value: 'basic', label: t('tools.config.mcp.auth_basic') },
    { value: 'oauth2', label: t('tools.config.mcp.auth_oauth') },
];

const selectedIntegration = computed<OAuth2IntegrationOption | undefined>(() =>
    props.oauth2Integrations.find((i) => i.id === integrationId.value),
);
</script>

<template>
    <div class="space-y-4">
        <p
            class="flex items-start gap-2 rounded-xs border border-soft bg-white/[0.02] p-2.5 text-[11px] leading-snug text-ink-muted"
        >
            <Info class="mt-px size-3.5 shrink-0 text-ink-subtle" />
            <span>{{ t('tools.config.mcp.guidance') }}</span>
        </p>

        <div class="grid gap-2">
            <Label for="endpoint">{{ t('tools.config.mcp.endpoint') }}</Label>
            <Input
                id="endpoint"
                v-model="endpoint"
                type="url"
                :placeholder="t('tools.config.mcp.endpoint_placeholder')"
                class="font-mono"
            />
            <p class="text-xs text-ink-muted">
                {{ t('tools.config.mcp.endpoint_description') }}
            </p>
            <InputError :message="errors['config.endpoint']" />
        </div>

        <div class="grid gap-2">
            <Label for="auth-type">{{ t('tools.config.mcp.auth_type') }}</Label>
            <Select v-model="authType">
                <SelectTrigger id="auth-type">
                    <SelectValue
                        :placeholder="t('tools.config.mcp.select_auth')"
                    />
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
                {{ t('tools.config.mcp.auth_description') }}
            </p>
            <InputError :message="errors['config.auth_type']" />
        </div>

        <!-- OAuth 2.0: link an integration that performs the web-auth flow. -->
        <div v-if="authType === 'oauth2'" class="grid gap-2">
            <Label for="oauth-integration">
                {{ t('tools.config.mcp.oauth_integration') }}
            </Label>

            <template v-if="oauth2Integrations.length > 0">
                <Select v-model="integrationId">
                    <SelectTrigger id="oauth-integration">
                        <SelectValue
                            :placeholder="t('tools.config.mcp.select_integration')"
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="integration in oauth2Integrations"
                            :key="integration.id"
                            :value="integration.id"
                        >
                            {{ integration.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-xs text-ink-muted">
                    {{ t('tools.config.mcp.oauth_integration_description') }}
                </p>
                <InputError :message="errors['config.integration_id']" />

                <!-- Per-user authorization status + the link that starts the
                     web auth flow. Authorization is per user, done from the
                     saved tool — so the link only shows once the tool exists. -->
                <div
                    v-if="selectedIntegration"
                    class="mt-1 flex items-center justify-between gap-3 rounded-xs border border-soft p-3"
                >
                    <div class="flex items-center gap-2 text-sm">
                        <CheckCircle2
                            v-if="selectedIntegration.authorized"
                            class="size-4 text-sp-success"
                        />
                        <span
                            :class="
                                selectedIntegration.authorized
                                    ? 'text-sp-success'
                                    : 'text-sp-warning'
                            "
                        >
                            {{
                                selectedIntegration.authorized
                                    ? t('tools.config.mcp.oauth_authorized')
                                    : t('tools.config.mcp.oauth_not_authorized')
                            }}
                        </span>
                    </div>
                    <a
                        v-if="oauth2AuthorizeUrl"
                        :href="oauth2AuthorizeUrl"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                    >
                        <ExternalLink class="size-3.5" />
                        {{
                            selectedIntegration.authorized
                                ? t('tools.config.mcp.oauth_reauthorize')
                                : t('tools.config.mcp.oauth_authorize')
                        }}
                    </a>
                    <span v-else class="text-[11px] text-ink-subtle">
                        {{ t('tools.config.mcp.oauth_save_first') }}
                    </span>
                </div>
            </template>

            <div v-else class="rounded-xs border border-dashed border-soft p-4">
                <p class="text-sm text-ink-muted">
                    {{ t('tools.config.mcp.oauth_no_integrations') }}
                </p>
                <a
                    href="/system/integrations/create"
                    class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-accent-blue hover:underline"
                >
                    <ExternalLink class="size-3.5" />
                    {{ t('tools.config.mcp.oauth_create_integration') }}
                </a>
            </div>
        </div>

        <div
            v-else-if="authType !== 'none'"
            class="rounded-xs border border-dashed border-soft p-4"
        >
            <p class="text-sm text-ink-muted">
                {{ t('tools.config.mcp.auth_credentials_note') }}
            </p>
        </div>
    </div>
</template>
