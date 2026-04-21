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
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { Tool, ToolReference, ToolTypeOption } from '@/types/tools';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Braces,
    Code,
    Database,
    Globe,
    Layers,
    Server,
    Settings2,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    tool: Tool;
    toolTypes: ToolTypeOption[];
    availableTools: ToolReference[];
}

const props = defineProps<Props>();

const form = useForm({
    name: props.tool.name,
    description: props.tool.description ?? '',
    status: props.tool.status,
    config: props.tool.config ?? {},
    tool_ids: props.tool.group_items?.map((item) => item.tool_id) ?? [],
});

const statusOptions = computed(() => [
    { value: 'draft', label: t('common.draft') },
    { value: 'active', label: t('common.active') },
    { value: 'inactive', label: t('common.inactive') },
]);

const submit = () => {
    form.put(ToolController.update({ tool: props.tool.id }).url);
};

// Per-type visual metadata mirrored from ToolTypeSelector / Create.
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

const typeTint = computed(() => typeTintMap[props.tool.type] ?? 'var(--sp-accent-blue)');
const typeIcon = computed<Component>(() => typeIconMap[props.tool.type] ?? Code);
</script>

<template>
    <Head :title="`${t('tools.edit.title')} ${tool.name}`" />

    <AppLayoutV2 :title="t('app_v2.nav.tools')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="`${t('tools.edit.title')} ${tool.name}`"
                :description="t('tools.edit.description')"
            />

            <form class="space-y-4" @submit.prevent="submit">
                <SettingsCard
                    :icon="typeIcon"
                    :title="t('tools.edit.basic_info')"
                    :description="t('tools.edit.basic_info_description')"
                    :tint="typeTint"
                >
                    <div class="space-y-1.5">
                        <Label for="name" class="text-xs text-ink-muted">
                            {{ t('tools.edit.tool_name') }}
                        </Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            :placeholder="t('tools.edit.tool_name_placeholder')"
                            class="h-9 border-medium bg-white/5 text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="description" class="text-xs text-ink-muted">
                            {{ t('tools.edit.description_label') }}
                        </Label>
                        <Textarea
                            id="description"
                            v-model="form.description"
                            :placeholder="t('tools.edit.description_placeholder')"
                            rows="3"
                            class="border-medium bg-white/5 text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="status" class="text-xs text-ink-muted">
                            Status
                        </Label>
                        <Select v-model="form.status">
                            <SelectTrigger
                                id="status"
                                class="h-9 border-medium bg-white/5"
                            >
                                <SelectValue placeholder="Select status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in statusOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.status" />
                    </div>
                </SettingsCard>

                <SettingsCard
                    :icon="Settings2"
                    :title="t('tools.edit.config_title')"
                    :description="t('tools.edit.config_description')"
                    :tint="typeTint"
                >
                    <FunctionToolConfig
                        v-if="tool.type === 'function'"
                        v-model:config="form.config"
                        :errors="form.errors"
                    />

                    <McpToolConfig
                        v-else-if="tool.type === 'mcp'"
                        v-model:config="form.config"
                        :errors="form.errors"
                    />

                    <RestApiToolConfig
                        v-else-if="tool.type === 'rest_api'"
                        v-model:config="form.config"
                        :errors="form.errors"
                    />

                    <GraphqlToolConfig
                        v-else-if="tool.type === 'graphql'"
                        v-model:config="form.config"
                        :errors="form.errors"
                    />

                    <DatabaseToolConfig
                        v-else-if="tool.type === 'database'"
                        v-model:config="form.config"
                        :errors="form.errors"
                    />

                    <GroupToolConfig
                        v-else-if="tool.type === 'group'"
                        v-model:tool-ids="form.tool_ids"
                        :available-tools="availableTools"
                        :errors="form.errors"
                    />
                </SettingsCard>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <Link :href="ToolController.show({ tool: tool.id }).url">
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
                        {{ t('common.save_changes') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
