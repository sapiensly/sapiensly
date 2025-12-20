<script setup lang="ts">
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import DatabaseToolConfig from '@/components/tools/DatabaseToolConfig.vue';
import FunctionToolConfig from '@/components/tools/FunctionToolConfig.vue';
import GraphqlToolConfig from '@/components/tools/GraphqlToolConfig.vue';
import GroupToolConfig from '@/components/tools/GroupToolConfig.vue';
import McpToolConfig from '@/components/tools/McpToolConfig.vue';
import RestApiToolConfig from '@/components/tools/RestApiToolConfig.vue';
import ToolTypeSelector from '@/components/tools/ToolTypeSelector.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { ToolReference, ToolType, ToolTypeOption } from '@/types/tools';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface Props {
    selectedType: ToolType | null;
    toolTypes: ToolTypeOption[];
    availableTools: ToolReference[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tools', href: ToolController.index().url },
    { title: 'Create', href: '#' },
];

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
</script>

<template>
    <Head title="Create Tool" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-2xl">
                <Heading
                    title="Create Tool"
                    description="Create a new tool for your agents"
                />

                <div v-if="!currentType" class="mt-8">
                    <HeadingSmall
                        title="Select Tool Type"
                        description="Choose the type of tool you want to create"
                    />
                    <ToolTypeSelector
                        :tool-types="toolTypes"
                        class="mt-4"
                        @select="selectType"
                    />
                </div>

                <form v-else class="mt-8 space-y-8" @submit.prevent="submit">
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Basic Information"
                            description="Name and describe your tool"
                        />

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="name">Tool Name</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    required
                                    placeholder="My Tool"
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">Description</Label>
                                <Textarea
                                    id="description"
                                    v-model="form.description"
                                    placeholder="What does this tool do?"
                                    rows="3"
                                />
                                <InputError :message="form.errors.description" />
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <HeadingSmall
                            :title="`${currentType.charAt(0).toUpperCase() + currentType.slice(1)} Configuration`"
                            description="Type-specific settings for this tool"
                        />

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
                    </div>

                    <div class="flex justify-end gap-4">
                        <Button variant="outline" type="button" @click="currentType = null">
                            Change Type
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="ToolController.index().url">
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            Create Tool
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
