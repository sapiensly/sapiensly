<script setup lang="ts">
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import DatabaseToolConfig from '@/components/tools/DatabaseToolConfig.vue';
import FunctionToolConfig from '@/components/tools/FunctionToolConfig.vue';
import GraphqlToolConfig from '@/components/tools/GraphqlToolConfig.vue';
import GroupToolConfig from '@/components/tools/GroupToolConfig.vue';
import McpToolConfig from '@/components/tools/McpToolConfig.vue';
import RestApiToolConfig from '@/components/tools/RestApiToolConfig.vue';
import ToolTypeSelector from '@/components/tools/ToolTypeSelector.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { ToolReference, ToolType, ToolTypeOption } from '@/types/tools';
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
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    selectedType: ToolType | null;
    toolTypes: ToolTypeOption[];
    availableTools: ToolReference[];
}

const props = defineProps<Props>();

const currentType = ref<ToolType | null>(props.selectedType);

const form = useForm({
    type: props.selectedType ?? 'function',
    name: '',
    description: '',
    config: {} as Record<string, unknown>,
    tool_ids: [] as string[],
});

const selectType = (type: ToolType) => {
    currentType.value = type;
    form.type = type;
    form.config = getDefaultConfig(type);
};

const getDefaultConfig = (type: ToolType): Record<string, unknown> => {
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
            return {
                base_url: '',
                method: 'GET',
                path: '',
                headers: {},
                auth_type: 'none',
                auth_config: {},
                request_body_template: '',
            };
        case 'graphql':
            return {
                endpoint: '',
                operation_type: 'query',
                operation: '',
                variables_template: {},
                auth_type: 'none',
                auth_config: {},
            };
        case 'database':
            return {
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

const typeTint = computed(() => typeTintMap[currentType.value ?? ''] ?? 'var(--sp-accent-blue)');
const typeIcon = computed<Component>(() => typeIconMap[currentType.value ?? ''] ?? Code);
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
                            :placeholder="t('tools.create.tool_name_placeholder')"
                            class="h-9 border-medium bg-white/5 text-sm text-ink placeholder:text-ink-subtle"
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
                            :placeholder="t('tools.create.description_placeholder')"
                            rows="3"
                            class="border-medium bg-white/5 text-sm text-ink placeholder:text-ink-subtle"
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
                        v-model:config="form.config"
                        :errors="form.errors"
                    />

                    <McpToolConfig
                        v-else-if="currentType === 'mcp'"
                        v-model:config="form.config"
                        :errors="form.errors"
                    />

                    <RestApiToolConfig
                        v-else-if="currentType === 'rest_api'"
                        v-model:config="form.config"
                        :errors="form.errors"
                    />

                    <GraphqlToolConfig
                        v-else-if="currentType === 'graphql'"
                        v-model:config="form.config"
                        :errors="form.errors"
                    />

                    <DatabaseToolConfig
                        v-else-if="currentType === 'database'"
                        v-model:config="form.config"
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
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink-muted transition-colors hover:border-strong hover:text-ink"
                        @click="currentType = null"
                    >
                        {{ t('common.change_type') }}
                    </button>
                    <div class="flex items-center gap-2">
                        <Link :href="ToolController.index().url">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                            >
                                {{ t('common.cancel') }}
                            </button>
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing"
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
