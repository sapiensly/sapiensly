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
import type { RestApiConfig } from '@/types/tools';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    config: RestApiConfig;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:config': [config: RestApiConfig];
}>();

const updateField = <K extends keyof RestApiConfig>(
    field: K,
    value: RestApiConfig[K]
) => {
    emit('update:config', {
        ...props.config,
        [field]: value,
    });
};

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
    ['POST', 'PUT', 'PATCH'].includes(method.value)
);
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label for="base-url">Base URL</Label>
            <Input
                id="base-url"
                v-model="baseUrl"
                type="url"
                placeholder="https://api.example.com"
                class="font-mono"
            />
            <p class="text-xs text-muted-foreground">
                The base URL for the REST API
            </p>
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

        <div class="grid gap-2">
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
            <p class="text-xs text-muted-foreground">
                How to authenticate with the API
            </p>
            <InputError :message="errors['config.auth_type']" />
        </div>

        <div
            v-if="authType !== 'none'"
            class="rounded-lg border border-dashed p-4"
        >
            <p class="text-sm text-muted-foreground">
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
            <p class="text-xs text-muted-foreground">
                JSON template with <code class="bg-muted px-1 rounded">{"{{variable}}"}</code> placeholders
            </p>
            <InputError :message="errors['config.request_body_template']" />
        </div>
    </div>
</template>
