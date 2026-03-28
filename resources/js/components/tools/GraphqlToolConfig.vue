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
import type { GraphqlConfig } from '@/types/tools';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    config: GraphqlConfig;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:config': [config: GraphqlConfig];
}>();

const updateField = <K extends keyof GraphqlConfig>(
    field: K,
    value: GraphqlConfig[K],
) => {
    emit('update:config', {
        ...props.config,
        [field]: value,
    });
};

const endpoint = computed({
    get: () => props.config.endpoint ?? '',
    set: (value: string) => updateField('endpoint', value),
});

const operationType = computed({
    get: () => props.config.operation_type ?? 'query',
    set: (value: string) =>
        updateField('operation_type', value as GraphqlConfig['operation_type']),
});

const operation = computed({
    get: () => props.config.operation ?? '',
    set: (value: string) => updateField('operation', value),
});

const authType = computed({
    get: () => props.config.auth_type ?? 'none',
    set: (value: string) => {
        emit('update:config', {
            ...props.config,
            auth_type: value as GraphqlConfig['auth_type'],
            auth_config: {},
        });
    },
});

const operationTypes = [
    { value: 'query', label: t('tools.config.graphql.query') },
    { value: 'mutation', label: t('tools.config.graphql.mutation') },
];

const authOptions = [
    { value: 'none', label: t('tools.config.graphql.auth_none') },
    { value: 'bearer', label: t('tools.config.graphql.auth_bearer') },
    { value: 'api_key', label: t('tools.config.graphql.auth_api_key') },
];

const queryPlaceholder = `query GetOrder($id: ID!) {
  order(id: $id) {
    id
    status
    items {
      name
      quantity
    }
  }
}`;

const mutationPlaceholder = `mutation UpdateOrderStatus($id: ID!, $status: String!) {
  updateOrder(id: $id, status: $status) {
    id
    status
    updatedAt
  }
}`;

const placeholder = computed(() =>
    operationType.value === 'mutation' ? mutationPlaceholder : queryPlaceholder,
);
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label for="endpoint">GraphQL Endpoint</Label>
            <Input
                id="endpoint"
                v-model="endpoint"
                type="url"
                placeholder="https://api.example.com/graphql"
                class="font-mono"
            />
            <p class="text-xs text-muted-foreground">
                The URL of the GraphQL API endpoint
            </p>
            <InputError :message="errors['config.endpoint']" />
        </div>

        <div class="grid gap-2">
            <Label for="operation-type">Operation Type</Label>
            <Select v-model="operationType">
                <SelectTrigger id="operation-type">
                    <SelectValue placeholder="Select operation type" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in operationTypes"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <p class="text-xs text-muted-foreground">
                Query for reading data, Mutation for modifying data
            </p>
            <InputError :message="errors['config.operation_type']" />
        </div>

        <div class="grid gap-2">
            <Label for="operation">GraphQL Operation</Label>
            <Textarea
                id="operation"
                v-model="operation"
                :placeholder="placeholder"
                class="min-h-[200px] font-mono text-sm"
            />
            <p class="text-xs text-muted-foreground">
                The GraphQL query or mutation. Use variables like
                <code class="rounded bg-muted px-1">$variableName</code>
            </p>
            <InputError :message="errors['config.operation']" />
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
                How to authenticate with the GraphQL API
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
    </div>
</template>
