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
import { Button } from '@/components/ui/button';
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
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Tool, ToolReference, ToolTypeOption } from '@/types/tools';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    tool: Tool;
    toolTypes: ToolTypeOption[];
    availableTools: ToolReference[];
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Tools', href: ToolController.index().url },
    { title: props.tool.name, href: ToolController.show({ tool: props.tool.id }).url },
    { title: 'Edit', href: '#' },
]);

const form = useForm({
    name: props.tool.name,
    description: props.tool.description ?? '',
    status: props.tool.status,
    config: props.tool.config ?? {},
    tool_ids: props.tool.group_items?.map((item) => item.tool_id) ?? [],
});

const statusOptions = [
    { value: 'draft', label: 'Draft' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const submit = () => {
    form.put(ToolController.update({ tool: props.tool.id }).url);
};
</script>

<template>
    <Head :title="`Edit ${tool.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-2xl">
                <Heading
                    :title="`Edit ${tool.name}`"
                    description="Update your tool configuration"
                />

                <form class="mt-8 space-y-8" @submit.prevent="submit">
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

                            <div class="grid gap-2">
                                <Label for="status">Status</Label>
                                <Select v-model="form.status">
                                    <SelectTrigger id="status">
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
                        </div>
                    </div>

                    <div class="space-y-6">
                        <HeadingSmall
                            :title="`${tool.type.charAt(0).toUpperCase() + tool.type.slice(1)} Configuration`"
                            description="Type-specific settings for this tool"
                        />

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
                    </div>

                    <div class="flex justify-end gap-4">
                        <Button variant="outline" as-child>
                            <Link :href="ToolController.show({ tool: tool.id }).url">
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            Save Changes
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
