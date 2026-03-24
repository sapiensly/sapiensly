<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface ModelDefinition {
    id: string;
    label: string;
    capabilities: string[];
}

interface DriverOption {
    value: string;
    label: string;
    credential_fields: string[];
    models: ModelDefinition[];
}

interface ProviderData {
    id: string;
    name: string;
    driver: string;
    display_name: string;
    credentials: Record<string, string>;
    models: ModelDefinition[];
    is_default: boolean;
    is_default_embeddings: boolean;
    status: string;
}

interface Props {
    drivers: DriverOption[];
    mode: 'create' | 'edit';
    provider?: ProviderData;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('nav.system'), href: '#' },
    { title: t('system.ai_providers.title'), href: '/system/ai-providers' },
    { title: props.mode === 'create' ? t('system.ai_provider_form.add_breadcrumb') : t('system.ai_provider_form.edit_breadcrumb'), href: '#' },
]);

const form = useForm({
    name: props.provider?.name ?? '',
    driver: props.provider?.driver ?? '',
    display_name: props.provider?.display_name ?? '',
    credentials: props.provider?.credentials ?? { api_key: '' },
    models: props.provider?.models ?? ([] as ModelDefinition[]),
    is_default: props.provider?.is_default ?? false,
    is_default_embeddings: props.provider?.is_default_embeddings ?? false,
    status: props.provider?.status ?? 'active',
});

const selectedDriver = computed(() => {
    return props.drivers.find((d) => d.value === form.driver);
});

const catalogModels = computed(() => {
    return selectedDriver.value?.models ?? [];
});

const credentialFields = computed(() => {
    return selectedDriver.value?.credential_fields ?? ['api_key'];
});

const credentialFieldLabel = (field: string): string => {
    const labels: Record<string, string> = {
        api_key: 'API Key',
        url: 'Base URL',
        api_version: 'API Version',
        deployment: 'Deployment Name',
        embedding_deployment: 'Embedding Deployment',
    };
    return labels[field] ?? field;
};

const isModelSelected = (modelId: string): boolean => {
    return form.models.some((m) => m.id === modelId);
};

const toggleModel = (model: ModelDefinition) => {
    const index = form.models.findIndex((m) => m.id === model.id);
    if (index >= 0) {
        form.models.splice(index, 1);
    } else {
        form.models.push({ ...model });
    }
};

// When driver changes in create mode, auto-fill name and display_name
watch(
    () => form.driver,
    (driver) => {
        if (props.mode === 'create' && driver) {
            const driverOption = props.drivers.find((d) => d.value === driver);
            if (driverOption) {
                form.name = driver;
                form.display_name = driverOption.label;
                form.models = [...driverOption.models];
                form.credentials = { api_key: '' };
            }
        }
    },
);

const submit = () => {
    if (props.mode === 'create') {
        form.post('/system/ai-providers');
    } else {
        form.put(`/system/ai-providers/${props.provider!.id}`);
    }
};
</script>

<template>
    <Head :title="mode === 'create' ? t('system.ai_provider_form.add_title') : t('system.ai_provider_form.edit_title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-2xl">
                <Heading
                    :title="mode === 'create' ? t('system.ai_provider_form.add_title') : t('system.ai_provider_form.edit_title')"
                    :description="mode === 'create'
                        ? t('system.ai_provider_form.add_description')
                        : t('system.ai_provider_form.edit_description')"
                />

                <form class="mt-8 space-y-6" @submit.prevent="submit">
                    <!-- Driver selection (only on create) -->
                    <div v-if="mode === 'create'" class="grid gap-2">
                        <Label for="driver">{{ t('system.ai_provider_form.provider') }}</Label>
                        <Select v-model="form.driver">
                            <SelectTrigger id="driver">
                                <SelectValue placeholder="Select an AI provider" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="driver in drivers"
                                    :key="driver.value"
                                    :value="driver.value"
                                >
                                    {{ driver.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.driver" />
                    </div>

                    <template v-if="form.driver">
                        <!-- Display name -->
                        <div class="grid gap-2">
                            <Label for="display_name">{{ t('system.ai_provider_form.display_name') }}</Label>
                            <Input
                                id="display_name"
                                v-model="form.display_name"
                                placeholder="e.g. Anthropic"
                            />
                            <InputError :message="form.errors.display_name" />
                        </div>

                        <!-- Credentials -->
                        <div class="space-y-4">
                            <Label class="text-base font-medium">{{ t('system.ai_provider_form.credentials') }}</Label>
                            <div
                                v-for="field in credentialFields"
                                :key="field"
                                class="grid gap-2"
                            >
                                <Label :for="`cred_${field}`">{{ credentialFieldLabel(field) }}</Label>
                                <Input
                                    :id="`cred_${field}`"
                                    v-model="form.credentials[field]"
                                    :type="field === 'api_key' ? 'password' : 'text'"
                                    :placeholder="mode === 'edit' && field === 'api_key' ? 'Leave blank to keep current key' : ''"
                                />
                                <InputError :message="(form.errors as any)[`credentials.${field}`]" />
                            </div>
                        </div>

                        <!-- Models -->
                        <div class="space-y-4">
                            <div>
                                <Label class="text-base font-medium">Available Models</Label>
                                <p class="text-sm text-muted-foreground">
                                    Select which models to make available for agent creation
                                </p>
                            </div>

                            <div v-if="catalogModels.length > 0" class="space-y-2">
                                <div
                                    v-for="model in catalogModels"
                                    :key="model.id"
                                    class="flex items-center gap-3 rounded-md border p-3"
                                >
                                    <Checkbox
                                        :id="`model_${model.id}`"
                                        :checked="isModelSelected(model.id)"
                                        @update:checked="toggleModel(model)"
                                    />
                                    <label :for="`model_${model.id}`" class="flex-1 cursor-pointer">
                                        <div class="text-sm font-medium">{{ model.label }}</div>
                                        <div class="text-xs text-muted-foreground">
                                            {{ model.id }} &middot; {{ model.capabilities.join(', ') }}
                                        </div>
                                    </label>
                                </div>
                            </div>
                            <p v-else class="text-sm text-muted-foreground">
                                No predefined models for this provider. Models can be added manually after creation.
                            </p>
                        </div>

                        <!-- Defaults -->
                        <div class="space-y-4">
                            <div class="flex items-center justify-between rounded-md border p-3">
                                <div>
                                    <div class="text-sm font-medium">Default LLM Provider</div>
                                    <div class="text-xs text-muted-foreground">
                                        Use this provider by default for agent chat
                                    </div>
                                </div>
                                <Switch v-model:checked="form.is_default" />
                            </div>

                            <div class="flex items-center justify-between rounded-md border p-3">
                                <div>
                                    <div class="text-sm font-medium">Default Embeddings Provider</div>
                                    <div class="text-xs text-muted-foreground">
                                        Use this provider by default for document embeddings
                                    </div>
                                </div>
                                <Switch v-model:checked="form.is_default_embeddings" />
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="grid gap-2">
                            <Label for="status">Status</Label>
                            <Select v-model="form.status">
                                <SelectTrigger id="status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active">{{ t('common.active') }}</SelectItem>
                                    <SelectItem value="inactive">{{ t('common.inactive') }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </template>

                    <!-- Actions -->
                    <div class="flex justify-end gap-4">
                        <Button variant="outline" as-child>
                            <Link href="/system/ai-providers">{{ t('common.cancel') }}</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing || !form.driver">
                            {{ mode === 'create' ? 'Add Provider' : 'Update Provider' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
