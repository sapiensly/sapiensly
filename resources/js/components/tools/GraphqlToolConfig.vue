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
import type { GraphqlConfig, HttpConnectionOption } from '@/types/tools';
import { ExternalLink, Info, Plug } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        config: GraphqlConfig;
        errors: Record<string, string>;
        connections?: HttpConnectionOption[];
    }>(),
    { connections: () => [] },
);

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

// See RestApiToolConfig: a tool either borrows its endpoint + auth from a
// Connection or carries them inline. INLINE marks the inline choice.
const INLINE = '__inline__';

const isConnected = computed(() => !!props.config.integration_id);

const connectionValue = computed({
    get: () => props.config.integration_id || INLINE,
    set: (value: string) => {
        if (value === INLINE) {
            const next = { ...props.config };
            delete next.integration_id;
            next.endpoint = next.endpoint ?? '';
            next.auth_type = next.auth_type ?? 'none';
            next.auth_config = next.auth_config ?? {};
            emit('update:config', next);
        } else {
            const next = { ...props.config, integration_id: value };
            delete next.endpoint;
            delete next.auth_type;
            delete next.auth_config;
            emit('update:config', next);
        }
    },
});

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
        <p
            class="flex items-start gap-2 rounded-xs border border-soft bg-white/[0.02] p-2.5 text-[11px] leading-snug text-ink-muted"
        >
            <Info class="mt-px size-3.5 shrink-0 text-ink-subtle" />
            <span>{{ t('tools.config.graphql.guidance') }}</span>
        </p>

        <!-- Connection: when set, the endpoint + auth come from it. -->
        <div class="grid gap-2">
            <Label for="connection">{{ t('tools.config.connection.label') }}</Label>
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
            <Label for="endpoint">GraphQL Endpoint</Label>
            <Input
                id="endpoint"
                v-model="endpoint"
                type="url"
                placeholder="https://api.example.com/graphql"
                class="font-mono"
            />
            <p class="text-xs text-ink-muted">
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
            <p class="text-xs text-ink-muted">
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
            <p class="text-xs text-ink-muted">
                The GraphQL query or mutation. Use variables like
                <code class="rounded bg-white/[0.06] px-1">$variableName</code>
            </p>
            <InputError :message="errors['config.operation']" />
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
                How to authenticate with the GraphQL API
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
    </div>
</template>
