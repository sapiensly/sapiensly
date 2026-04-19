<script setup lang="ts">
import HeaderEditor, { type HeaderRow } from '@/components/integrations/HeaderEditor.vue';
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
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    authType: string;
    modelValue: Record<string, unknown>;
    maskedValues?: Record<string, unknown>;
}

const props = defineProps<Props>();

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
</script>

<template>
    <div v-if="authType === 'api_key'" class="space-y-3">
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
        </div>
        <div class="grid gap-2">
            <Label>{{ t('system.integrations.auth.oauth2.client_id') }}</Label>
            <Input
                :model-value="(modelValue.client_id as string) ?? ''"
                @update:model-value="update('client_id', $event)"
            />
        </div>
        <div class="grid gap-2">
            <Label>{{ t('system.integrations.auth.oauth2.client_secret') }}</Label>
            <Input
                type="password"
                :model-value="(modelValue.client_secret as string) ?? ''"
                :placeholder="placeholderFor('client_secret')"
                @update:model-value="update('client_secret', $event)"
            />
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
        </div>
        <div class="grid gap-2">
            <Label>{{ t('system.integrations.auth.oauth2.token_url') }}</Label>
            <Input
                :model-value="(modelValue.token_url as string) ?? ''"
                @update:model-value="update('token_url', $event)"
            />
        </div>
        <div class="grid gap-2">
            <Label>{{ t('system.integrations.auth.oauth2.client_id') }}</Label>
            <Input
                :model-value="(modelValue.client_id as string) ?? ''"
                @update:model-value="update('client_id', $event)"
            />
        </div>
        <div class="grid gap-2">
            <Label>{{ t('system.integrations.auth.oauth2.client_secret') }}</Label>
            <Input
                type="password"
                :model-value="(modelValue.client_secret as string) ?? ''"
                :placeholder="placeholderFor('client_secret')"
                @update:model-value="update('client_secret', $event)"
            />
        </div>
        <div class="grid gap-2">
            <Label>{{ t('system.integrations.auth.oauth2.redirect_uri') }}</Label>
            <Input
                :model-value="(modelValue.redirect_uri as string) ?? ''"
                placeholder="https://your-app.com/oauth/integrations/callback"
                @update:model-value="update('redirect_uri', $event)"
            />
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
</template>
