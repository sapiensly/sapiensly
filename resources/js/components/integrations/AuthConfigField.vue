<script setup lang="ts">
import HeaderEditor, { type HeaderRow } from '@/components/integrations/HeaderEditor.vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Check, Copy, Info, ShieldCheck } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    authType: string;
    modelValue: Record<string, unknown>;
    maskedValues?: Record<string, unknown>;
    callbackUrl?: string;
    errors?: Record<string, string>;
}

const props = defineProps<Props>();

function errorFor(field: string): string | undefined {
    return props.errors?.[`auth_config.${field}`];
}

const emit = defineEmits<{
    'update:modelValue': [config: Record<string, unknown>];
}>();

const { t } = useI18n();

function update(key: string, value: unknown): void {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

const customHeaders = computed<HeaderRow[]>(() => {
    const raw = (props.modelValue.headers ?? []) as Array<{ name: string; value: string }>;

    return raw.map((h) => ({ key: h.name, value: h.value, enabled: true }));
});

function updateCustomHeaders(rows: HeaderRow[]): void {
    update(
        'headers',
        rows.map((r) => ({ name: r.key, value: r.value })),
    );
}

function placeholderFor(field: string): string {
    if (props.maskedValues && typeof props.maskedValues[field] === 'string') {
        const masked = props.maskedValues[field] as string;
        if (masked && masked !== '') return t('system.integrations.auth.kept_secret');
    }
    return '';
}

// A one-line "what to enter / what happens" per method, so each form reads as
// a guided step rather than a bag of fields.
const explainerKey = computed<string | null>(() => {
    const known = [
        'api_key',
        'bearer',
        'basic',
        'custom_headers',
        'oauth2_client_credentials',
        'oauth2_auth_code',
    ];
    return known.includes(props.authType)
        ? `system.integrations.auth.explainer.${props.authType}`
        : null;
});

// The redirect URI is this app's own callback — the user registers it in their
// provider, they don't invent it. Show it read-only with a one-tap copy.
const redirectUri = computed(
    () => ((props.modelValue.redirect_uri as string) || props.callbackUrl) ?? '',
);
const copied = ref(false);
async function copyRedirect(): Promise<void> {
    if (!redirectUri.value) return;
    await navigator.clipboard.writeText(redirectUri.value);
    copied.value = true;
    window.setTimeout(() => (copied.value = false), 1500);
}

// Persist the callback as the redirect URI when editing an OAuth (web)
// connection that doesn't carry one yet, so the read-only field is real.
onMounted(() => {
    if (
        props.authType === 'oauth2_auth_code' &&
        !props.modelValue.redirect_uri &&
        props.callbackUrl
    ) {
        update('redirect_uri', props.callbackUrl);
    }
});
</script>

<template>
    <div class="space-y-3">
        <!-- Per-method guidance. -->
        <p
            v-if="explainerKey"
            class="flex items-start gap-2 rounded-xs border border-soft bg-white/[0.02] p-2.5 text-[11px] leading-snug text-ink-muted"
        >
            <Info class="mt-px size-3.5 shrink-0 text-ink-subtle" />
            <span>{{ t(explainerKey) }}</span>
        </p>

        <!-- No auth — make the "nothing to do here" state explicit. -->
        <div
            v-if="authType === 'none'"
            class="flex items-start gap-2 rounded-xs border border-soft bg-white/[0.02] p-3 text-xs text-ink-muted"
        >
            <ShieldCheck class="mt-px size-4 shrink-0 text-ink-subtle" />
            <span>{{ t('system.integrations.auth.none_note') }}</span>
        </div>

        <div v-else-if="authType === 'api_key'" class="space-y-3">
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.api_key.location') }}</Label>
                <Select
                    :model-value="(modelValue.location as string) ?? 'header'"
                    @update:model-value="update('location', $event)"
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="header">
                            {{ t('system.integrations.auth.api_key.location_header') }}
                        </SelectItem>
                        <SelectItem value="query">
                            {{ t('system.integrations.auth.api_key.location_query') }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.api_key.name') }}</Label>
                <Input
                    :model-value="(modelValue.name as string) ?? ''"
                    placeholder="X-Api-Key"
                    @update:model-value="update('name', $event)"
                />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.api_key.value') }}</Label>
                <Input
                    type="password"
                    :model-value="(modelValue.value as string) ?? ''"
                    :placeholder="placeholderFor('value')"
                    @update:model-value="update('value', $event)"
                />
            </div>
        </div>

        <div v-else-if="authType === 'bearer'" class="space-y-3">
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.bearer.token') }}</Label>
                <Input
                    type="password"
                    :model-value="(modelValue.token as string) ?? ''"
                    :placeholder="placeholderFor('token')"
                    @update:model-value="update('token', $event)"
                />
            </div>
        </div>

        <div v-else-if="authType === 'basic'" class="space-y-3">
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.basic.username') }}</Label>
                <Input
                    :model-value="(modelValue.username as string) ?? ''"
                    @update:model-value="update('username', $event)"
                />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.basic.password') }}</Label>
                <Input
                    type="password"
                    :model-value="(modelValue.password as string) ?? ''"
                    :placeholder="placeholderFor('password')"
                    @update:model-value="update('password', $event)"
                />
            </div>
        </div>

        <div v-else-if="authType === 'custom_headers'" class="space-y-3">
            <Label>{{ t('system.integrations.auth.custom.header_name') }}</Label>
            <HeaderEditor
                :model-value="customHeaders"
                @update:model-value="updateCustomHeaders"
            />
        </div>

        <div v-else-if="authType === 'oauth2_client_credentials'" class="space-y-3">
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.oauth2.token_url') }}</Label>
                <Input
                    :model-value="(modelValue.token_url as string) ?? ''"
                    @update:model-value="update('token_url', $event)"
                />
                <InputError :message="errorFor('token_url')" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.oauth2.client_id') }}</Label>
                <Input
                    :model-value="(modelValue.client_id as string) ?? ''"
                    @update:model-value="update('client_id', $event)"
                />
                <InputError :message="errorFor('client_id')" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.oauth2.client_secret') }}</Label>
                <Input
                    type="password"
                    :model-value="(modelValue.client_secret as string) ?? ''"
                    :placeholder="placeholderFor('client_secret')"
                    @update:model-value="update('client_secret', $event)"
                />
                <InputError :message="errorFor('client_secret')" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.oauth2.scope') }}</Label>
                <Input
                    :model-value="(modelValue.scope as string) ?? ''"
                    @update:model-value="update('scope', $event)"
                />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.oauth2.audience') }}</Label>
                <Input
                    :model-value="(modelValue.audience as string) ?? ''"
                    @update:model-value="update('audience', $event)"
                />
            </div>
        </div>

        <div v-else-if="authType === 'oauth2_auth_code'" class="space-y-3">
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.oauth2.authorize_url') }}</Label>
                <Input
                    :model-value="(modelValue.authorize_url as string) ?? ''"
                    @update:model-value="update('authorize_url', $event)"
                />
                <InputError :message="errorFor('authorize_url')" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.oauth2.token_url') }}</Label>
                <Input
                    :model-value="(modelValue.token_url as string) ?? ''"
                    @update:model-value="update('token_url', $event)"
                />
                <InputError :message="errorFor('token_url')" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.oauth2.client_id') }}</Label>
                <Input
                    :model-value="(modelValue.client_id as string) ?? ''"
                    @update:model-value="update('client_id', $event)"
                />
                <InputError :message="errorFor('client_id')" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.oauth2.client_secret') }}</Label>
                <Input
                    type="password"
                    :model-value="(modelValue.client_secret as string) ?? ''"
                    :placeholder="placeholderFor('client_secret')"
                    @update:model-value="update('client_secret', $event)"
                />
                <InputError :message="errorFor('client_secret')" />
            </div>
            <!-- Redirect URI: this app's own callback. Read-only + copy — the
                 user pastes it into their provider, never edits it. -->
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.oauth2.redirect_uri') }}</Label>
                <div class="flex items-center gap-2">
                    <Input
                        :model-value="redirectUri"
                        readonly
                        class="font-mono text-xs"
                    />
                    <button
                        type="button"
                        :title="t('common.copy')"
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-xs border border-medium bg-surface text-ink-muted transition-colors hover:border-strong hover:text-ink"
                        @click="copyRedirect"
                    >
                        <Check v-if="copied" class="size-4 text-sp-success" />
                        <Copy v-else class="size-4" />
                    </button>
                </div>
                <p class="text-[11px] text-ink-subtle">
                    {{ t('system.integrations.auth.oauth2.redirect_uri_hint') }}
                </p>
            </div>
            <div class="grid gap-2">
                <Label>{{ t('system.integrations.auth.oauth2.scope') }}</Label>
                <Input
                    :model-value="(modelValue.scope as string) ?? ''"
                    @update:model-value="update('scope', $event)"
                />
            </div>
            <div class="flex items-center gap-2">
                <Checkbox
                    :model-value="(modelValue.pkce as boolean) ?? true"
                    @update:model-value="update('pkce', $event === true)"
                />
                <Label>{{ t('system.integrations.auth.oauth2.pkce') }}</Label>
            </div>
        </div>
    </div>
</template>
