<script setup lang="ts">
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import DatabaseToolConfig from '@/components/tools/DatabaseToolConfig.vue';
import FunctionToolConfig from '@/components/tools/FunctionToolConfig.vue';
import GraphqlToolConfig from '@/components/tools/GraphqlToolConfig.vue';
import GroupToolConfig from '@/components/tools/GroupToolConfig.vue';
import McpConnectionPicker from '@/components/tools/McpConnectionPicker.vue';
import RestApiToolConfig from '@/components/tools/RestApiToolConfig.vue';
import ToolTypeSelector from '@/components/tools/ToolTypeSelector.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type {
    DatabaseConfig,
    FunctionConfig,
    GraphqlConfig,
    HttpConnectionOption,
    McpConnectionOption,
    RestApiConfig,
    ToolConfig,
    ToolReference,
    ToolType,
    ToolTypeOption,
} from '@/types/tools';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Braces,
    Code,
    Database,
    Globe,
    Layers,
    Server,
    Settings2,
    Sparkles,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    selectedType: ToolType | null;
    toolTypes: ToolTypeOption[];
    availableTools: ToolReference[];
    mcpConnections: McpConnectionOption[];
    httpConnections: HttpConnectionOption[];
    dbConnections: HttpConnectionOption[];
}

const props = defineProps<Props>();

const currentType = ref<ToolType | null>(props.selectedType);

interface ToolCreateForm {
    type: ToolType;
    name: string;
    description: string;
    config: ToolConfig;
    tool_ids: string[];
}

const form = useForm<ToolCreateForm>({
    type: props.selectedType ?? 'function',
    name: '',
    description: '',
    config: {},
    tool_ids: [],
});

const selectType = (type: ToolType) => {
    currentType.value = type;
    form.type = type;
    form.config = getDefaultConfig(type);
};

const getDefaultConfig = (type: ToolType): ToolConfig => {
    switch (type) {
        case 'function':
            return {
                name: '',
                description: '',
                parameters: {
                    type: 'object',
                    properties: {},
                    required: [],
                },
            };
        case 'mcp':
            return {
                endpoint: '',
                auth_type: 'none',
                auth_config: {},
            };
        case 'rest_api':
            // Default to the connected path when a connection exists; fall back
            // to inline only when there's nothing to connect to yet.
            return props.httpConnections.length > 0
                ? {
                      integration_id: props.httpConnections[0].id,
                      method: 'GET',
                      path: '',
                      headers: {},
                      request_body_template: '',
                  }
                : {
                      base_url: '',
                      method: 'GET',
                      path: '',
                      headers: {},
                      auth_type: 'none',
                      auth_config: {},
                      request_body_template: '',
                  };
        case 'graphql':
            return props.httpConnections.length > 0
                ? {
                      integration_id: props.httpConnections[0].id,
                      operation_type: 'query',
                      operation: '',
                      variables_template: {},
                  }
                : {
                      endpoint: '',
                      operation_type: 'query',
                      operation: '',
                      variables_template: {},
                      auth_type: 'none',
                      auth_config: {},
                  };
        case 'database':
            return props.dbConnections.length > 0
                ? {
                      integration_id: props.dbConnections[0].id,
                      query_template: '',
                      read_only: true,
                  }
                : {
                      driver: 'pgsql',
                      host: '',
                      port: 5432,
                      database: '',
                      username: '',
                      password: '',
                      query_template: '',
                      read_only: true,
                  };
        case 'group':
            return {};
        default:
            return {};
    }
};

// The shared `form.config` holds the union of every tool-type config; each
// config editor wants its own concrete shape, so expose narrowed two-way
// views keyed by the currently selected type.
const functionConfig = computed<FunctionConfig>({
    get: () => form.config as FunctionConfig,
    set: (value) => {
        form.config = value;
    },
});

const restApiConfig = computed<RestApiConfig>({
    get: () => form.config as RestApiConfig,
    set: (value) => {
        form.config = value;
    },
});

const graphqlConfig = computed<GraphqlConfig>({
    get: () => form.config as GraphqlConfig,
    set: (value) => {
        form.config = value;
    },
});

const databaseConfig = computed<DatabaseConfig>({
    get: () => form.config as DatabaseConfig,
    set: (value) => {
        form.config = value;
    },
});

// MCP tools are created by picking an existing connection — the endpoint and
// auth come from the integration, so there's nothing to fill in by hand.
const selectedMcpId = ref<string | null>(null);

const selectMcpConnection = (connection: McpConnectionOption) => {
    selectedMcpId.value = connection.id;
    form.name = connection.name;
    form.config = connection.requires_auth
        ? {
              endpoint: connection.base_url,
              auth_type: 'oauth2',
              integration_id: connection.id,
          }
        : {
              endpoint: connection.base_url,
              auth_type: 'none',
              auth_config: {},
          };
};

const mcpReady = computed(
    () => currentType.value !== 'mcp' || selectedMcpId.value !== null,
);

const submit = () => {
    form.post(ToolController.store().url);
};

watch(currentType, (type) => {
    if (type) {
        selectType(type);
    }
});

if (props.selectedType) {
    selectType(props.selectedType);
}

// Per-type visual metadata — same mapping as ToolTypeSelector so the form
// card headers pick up the tint / icon of the selected type.
const typeTintMap: Record<string, string> = {
    function: 'var(--sp-accent-blue)',
    mcp: 'var(--sp-success)',
    group: 'var(--sp-spectrum-magenta)',
    rest_api: 'var(--sp-warning)',
    graphql: 'var(--sp-spectrum-indigo)',
    database: 'var(--sp-accent-cyan)',
};

const typeIconMap: Record<string, Component> = {
    function: Code,
    mcp: Server,
    group: Layers,
    rest_api: Globe,
    graphql: Braces,
    database: Database,
};

const typeTint = computed(
    () => typeTintMap[currentType.value ?? ''] ?? 'var(--sp-accent-blue)',
);
const typeIcon = computed<Component>(
    () => typeIconMap[currentType.value ?? ''] ?? Code,
);
</script>

<template>
    <Head :title="t('tools.create.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.tools')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="t('tools.create.heading')"
                :description="t('tools.create.description')"
            />

            <SettingsCard
                v-if="!currentType"
                :icon="Sparkles"
                :title="t('tools.create.select_type')"
                :description="t('tools.create.select_type_description')"
                tint="var(--sp-accent-cyan)"
            >
                <ToolTypeSelector
                    :tool-types="toolTypes"
                    @select="selectType"
                />
            </SettingsCard>

            <form v-else class="space-y-4" @submit.prevent="submit">
                <SettingsCard
                    v-if="currentType !== 'mcp'"
                    :icon="typeIcon"
                    :title="t('tools.create.basic_info')"
                    :description="t('tools.create.basic_info_description')"
                    :tint="typeTint"
                >
                    <div class="space-y-1.5">
                        <Label for="name" class="text-xs text-ink-muted">
                            {{ t('tools.create.tool_name') }}
                        </Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            :placeholder="
                                t('tools.create.tool_name_placeholder')
                            "
                            class="h-9 border-medium bg-surface text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="description" class="text-xs text-ink-muted">
                            {{ t('tools.create.description_label') }}
                        </Label>
                        <Textarea
                            id="description"
                            v-model="form.description"
                            :placeholder="
                                t('tools.create.description_placeholder')
                            "
                            rows="3"
                            class="border-medium bg-surface text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                </SettingsCard>

                <SettingsCard
                    :icon="Settings2"
                    :title="t('tools.create.config_title')"
                    :description="t('tools.create.config_description')"
                    :tint="typeTint"
                >
                    <FunctionToolConfig
                        v-if="currentType === 'function'"
                        v-model:config="functionConfig"
                        :errors="form.errors"
                    />

                    <McpConnectionPicker
                        v-else-if="currentType === 'mcp'"
                        :connections="mcpConnections"
                        :selected-id="selectedMcpId"
                        :error="
                            form.errors['config.integration_id'] ||
                            form.errors['config.endpoint']
                        "
                        @select="selectMcpConnection"
                    />

                    <RestApiToolConfig
                        v-else-if="currentType === 'rest_api'"
                        v-model:config="restApiConfig"
                        :connections="httpConnections"
                        :errors="form.errors"
                    />

                    <GraphqlToolConfig
                        v-else-if="currentType === 'graphql'"
                        v-model:config="graphqlConfig"
                        :connections="httpConnections"
                        :errors="form.errors"
                    />

                    <DatabaseToolConfig
                        v-else-if="currentType === 'database'"
                        v-model:config="databaseConfig"
                        :connections="dbConnections"
                        :errors="form.errors"
                    />

                    <GroupToolConfig
                        v-else-if="currentType === 'group'"
                        v-model:tool-ids="form.tool_ids"
                        :available-tools="availableTools"
                        :errors="form.errors"
                    />
                </SettingsCard>

                <div class="flex items-center justify-between gap-2 pt-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink-muted transition-colors hover:border-strong hover:text-ink"
                        @click="currentType = null"
                    >
                        {{ t('common.change_type') }}
                    </button>
                    <div class="flex items-center gap-2">
                        <Link :href="ToolController.index().url">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                            >
                                {{ t('common.cancel') }}
                            </button>
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing || !mcpReady"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                        >
                            {{ t('tools.create.submit') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
