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
import type { McpConfig } from '@/types/tools';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    config: McpConfig;
    errors: Record<string, string>;
}>();

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
        });
    },
});

const authOptions = [
    { value: 'none', label: t('tools.config.mcp.auth_none') },
    { value: 'bearer', label: t('tools.config.mcp.auth_bearer') },
    { value: 'api_key', label: t('tools.config.mcp.auth_api_key') },
    { value: 'basic', label: t('tools.config.mcp.auth_basic') },
];
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label for="endpoint">{{ t('tools.config.mcp.endpoint') }}</Label>
            <Input
                id="endpoint"
                v-model="endpoint"
                type="url"
                :placeholder="t('tools.config.mcp.endpoint_placeholder')"
                class="font-mono"
            />
            <p class="text-xs text-muted-foreground">
                {{ t('tools.config.mcp.endpoint_description') }}
            </p>
            <InputError :message="errors['config.endpoint']" />
        </div>

        <div class="grid gap-2">
            <Label for="auth-type">{{ t('tools.config.mcp.auth_type') }}</Label>
            <Select v-model="authType">
                <SelectTrigger id="auth-type">
                    <SelectValue :placeholder="t('tools.config.mcp.select_auth')" />
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
            <p class="text-xs text-muted-foreground">
                How to authenticate with the MCP server
            </p>
            <InputError :message="errors['config.auth_type']" />
        </div>

        <div
            v-if="authType !== 'none'"
            class="rounded-lg border border-dashed p-4"
        >
            <p class="text-sm text-muted-foreground">
                Authentication credentials will be configured securely after creating the tool.
            </p>
        </div>
    </div>
</template>
