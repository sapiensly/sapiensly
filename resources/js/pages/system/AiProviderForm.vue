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
import { Bot, Database } from 'lucide-vue-next';
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
    {
        title:
            props.mode === 'create'
                ? t('system.ai_provider_form.add_breadcrumb')
                : t('system.ai_provider_form.edit_breadcrumb'),
        href: '#',
    },
]);

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

const hasCapability = (model: ModelDefinition, capability: string): boolean =>
    model.capabilities.includes(capability);

const driversWithChat = computed<DriverOption[]>(() =>
    props.drivers.filter((d) =>
        d.models.some((m) => hasCapability(m, 'chat')),
    ),
);

const driversWithEmbeddings = computed<DriverOption[]>(() =>
    props.drivers.filter((d) =>
        d.models.some((m) => hasCapability(m, 'embeddings')),
    ),
);

// =====================================================================
// CREATE MODE — LLM + Embeddings two-section form
// =====================================================================
const emptyCredentials = (): Record<string, string> => ({ api_key: '' });

const createForm = useForm({
    llm: {
        driver: '',
        model_id: '',
        credentials: emptyCredentials(),
    },
    embeddings: {
        driver: '',
        model_id: '',
        credentials: emptyCredentials(),
        same_as_llm: true,
    },
});

const selectedLlmDriver = computed(() =>
    props.drivers.find((d) => d.value === createForm.llm.driver),
);

const selectedEmbeddingsDriver = computed(() =>
    props.drivers.find((d) => d.value === createForm.embeddings.driver),
);

const llmCredentialFields = computed<string[]>(
    () => selectedLlmDriver.value?.credential_fields ?? ['api_key'],
);

const embeddingsCredentialFields = computed<string[]>(
    () => selectedEmbeddingsDriver.value?.credential_fields ?? ['api_key'],
);

const llmModels = computed<ModelDefinition[]>(() =>
    (selectedLlmDriver.value?.models ?? []).filter((m) =>
        hasCapability(m, 'chat'),
    ),
);

const embeddingsModels = computed<ModelDefinition[]>(() =>
    (selectedEmbeddingsDriver.value?.models ?? []).filter((m) =>
        hasCapability(m, 'embeddings'),
    ),
);

const llmDriverHasEmbeddings = computed<boolean>(() =>
    (selectedLlmDriver.value?.models ?? []).some((m) =>
        hasCapability(m, 'embeddings'),
    ),
);

// When LLM driver changes, reset its model + credentials
watch(
    () => createForm.llm.driver,
    (driver) => {
        createForm.llm.model_id = '';
        createForm.llm.credentials = emptyCredentials();

        // If "same as LLM" is on but the new driver has no embeddings, turn it off
        if (createForm.embeddings.same_as_llm) {
            if (!driver || !llmDriverHasEmbeddings.value) {
                createForm.embeddings.same_as_llm = false;
            } else {
                createForm.embeddings.driver = driver;
                createForm.embeddings.model_id = '';
            }
        }
    },
);

// When "same as LLM" toggles, sync the embeddings driver
watch(
    () => createForm.embeddings.same_as_llm,
    (same) => {
        if (same) {
            if (!llmDriverHasEmbeddings.value) {
                createForm.embeddings.same_as_llm = false;
                return;
            }
            createForm.embeddings.driver = createForm.llm.driver;
            createForm.embeddings.model_id = '';
            createForm.embeddings.credentials = emptyCredentials();
        } else {
            createForm.embeddings.driver = '';
            createForm.embeddings.model_id = '';
            createForm.embeddings.credentials = emptyCredentials();
        }
    },
);

// Reset embeddings model when its driver changes
watch(
    () => createForm.embeddings.driver,
    () => {
        createForm.embeddings.model_id = '';
    },
);

const canSubmitCreate = computed<boolean>(() => {
    const llmOk =
        createForm.llm.driver &&
        createForm.llm.model_id &&
        !!createForm.llm.credentials.api_key;

    const embeddingsOk = createForm.embeddings.same_as_llm
        ? !!createForm.embeddings.model_id
        : createForm.embeddings.driver &&
          createForm.embeddings.model_id &&
          !!createForm.embeddings.credentials.api_key;

    return Boolean(llmOk && embeddingsOk);
});

const submitCreate = () => {
    // When "same as LLM", mirror credentials before posting so the backend
    // sees matching drivers+credentials and collapses into one provider row.
    if (createForm.embeddings.same_as_llm) {
        createForm.embeddings.driver = createForm.llm.driver;
        createForm.embeddings.credentials = { ...createForm.llm.credentials };
    }

    createForm.transform((data) => ({
        llm: {
            driver: data.llm.driver,
            model_id: data.llm.model_id,
            credentials: data.llm.credentials,
        },
        embeddings: {
            driver: data.embeddings.driver,
            model_id: data.embeddings.model_id,
            credentials: data.embeddings.credentials,
        },
    })).post('/system/ai-providers');
};

// =====================================================================
// EDIT MODE — single provider form (unchanged)
// =====================================================================
const editForm = useForm({
    display_name: props.provider?.display_name ?? '',
    credentials: props.provider?.credentials ?? { api_key: '' },
    models: props.provider?.models ?? ([] as ModelDefinition[]),
    is_default: props.provider?.is_default ?? false,
    is_default_embeddings: props.provider?.is_default_embeddings ?? false,
    status: props.provider?.status ?? 'active',
});

const editDriverOption = computed(() =>
    props.drivers.find((d) => d.value === props.provider?.driver),
);

const editCatalogModels = computed<ModelDefinition[]>(
    () => editDriverOption.value?.models ?? [],
);

const editCredentialFields = computed<string[]>(
    () => editDriverOption.value?.credential_fields ?? ['api_key'],
);

const isEditModelSelected = (modelId: string): boolean =>
    editForm.models.some((m) => m.id === modelId);

const toggleEditModel = (model: ModelDefinition) => {
    const index = editForm.models.findIndex((m) => m.id === model.id);
    if (index >= 0) {
        editForm.models.splice(index, 1);
    } else {
        editForm.models.push({ ...model });
    }
};

const submitEdit = () => {
    editForm.put(`/system/ai-providers/${props.provider!.id}`);
};
</script>

<template>
    <Head
        :title="
            mode === 'create'
                ? t('system.ai_provider_form.add_title')
                : t('system.ai_provider_form.edit_title')
        "
    />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-2xl">
                <Heading
                    :title="
                        mode === 'create'
                            ? t('system.ai_provider_form.add_title')
                            : t('system.ai_provider_form.edit_title')
                    "
                    :description="
                        mode === 'create'
                            ? t('system.ai_provider_form.add_description')
                            : t('system.ai_provider_form.edit_description')
                    "
                />

                <!-- =========================== CREATE MODE =========================== -->
                <form
                    v-if="mode === 'create'"
                    class="mt-8 space-y-6"
                    @submit.prevent="submitCreate"
                >
                    <!-- Default LLM section -->
                    <section class="rounded-lg border p-5">
                        <div class="mb-4 flex items-start gap-3">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                            >
                                <Bot class="h-5 w-5" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold">
                                    {{ t('system.ai_provider_form.default_llm') }}
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        t(
                                            'system.ai_provider_form.default_llm_description',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div class="grid gap-2">
                                <Label for="llm_driver">{{
                                    t('system.ai_provider_form.provider')
                                }}</Label>
                                <Select v-model="createForm.llm.driver">
                                    <SelectTrigger id="llm_driver">
                                        <SelectValue
                                            :placeholder="
                                                t(
                                                    'system.ai_provider_form.select_provider',
                                                )
                                            "
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="driver in driversWithChat"
                                            :key="driver.value"
                                            :value="driver.value"
                                        >
                                            {{ driver.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="(createForm.errors as any)['llm.driver']"
                                />
                            </div>

                            <template v-if="createForm.llm.driver">
                                <div
                                    v-for="field in llmCredentialFields"
                                    :key="`llm_${field}`"
                                    class="grid gap-2"
                                >
                                    <Label :for="`llm_cred_${field}`">{{
                                        credentialFieldLabel(field)
                                    }}</Label>
                                    <Input
                                        :id="`llm_cred_${field}`"
                                        v-model="createForm.llm.credentials[field]"
                                        :type="field === 'api_key' ? 'password' : 'text'"
                                    />
                                    <InputError
                                        :message="
                                            (createForm.errors as any)[
                                                `llm.credentials.${field}`
                                            ]
                                        "
                                    />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="llm_model">{{
                                        t('system.ai_provider_form.model')
                                    }}</Label>
                                    <Select v-model="createForm.llm.model_id">
                                        <SelectTrigger id="llm_model">
                                            <SelectValue
                                                :placeholder="
                                                    t(
                                                        'system.ai_provider_form.select_model',
                                                    )
                                                "
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="model in llmModels"
                                                :key="model.id"
                                                :value="model.id"
                                            >
                                                {{ model.label }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        :message="(createForm.errors as any)['llm.model_id']"
                                    />
                                </div>
                            </template>
                        </div>
                    </section>

                    <!-- Default Embeddings section -->
                    <section class="rounded-lg border p-5">
                        <div class="mb-4 flex items-start gap-3">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                            >
                                <Database class="h-5 w-5" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold">
                                    {{
                                        t('system.ai_provider_form.default_embeddings')
                                    }}
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        t(
                                            'system.ai_provider_form.default_embeddings_description',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div
                                v-if="createForm.llm.driver && llmDriverHasEmbeddings"
                                class="flex items-center gap-3 rounded-md border border-dashed p-3"
                            >
                                <Checkbox
                                    id="same_as_llm"
                                    :checked="createForm.embeddings.same_as_llm"
                                    @update:checked="
                                        createForm.embeddings.same_as_llm = $event
                                    "
                                />
                                <label
                                    for="same_as_llm"
                                    class="cursor-pointer text-sm"
                                >
                                    {{ t('system.ai_provider_form.same_as_llm') }}
                                </label>
                            </div>

                            <template v-if="!createForm.embeddings.same_as_llm">
                                <div class="grid gap-2">
                                    <Label for="embeddings_driver">{{
                                        t('system.ai_provider_form.provider')
                                    }}</Label>
                                    <Select v-model="createForm.embeddings.driver">
                                        <SelectTrigger id="embeddings_driver">
                                            <SelectValue
                                                :placeholder="
                                                    t(
                                                        'system.ai_provider_form.select_provider',
                                                    )
                                                "
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="driver in driversWithEmbeddings"
                                                :key="driver.value"
                                                :value="driver.value"
                                            >
                                                {{ driver.label }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        :message="
                                            (createForm.errors as any)['embeddings.driver']
                                        "
                                    />
                                </div>

                                <template v-if="createForm.embeddings.driver">
                                    <div
                                        v-for="field in embeddingsCredentialFields"
                                        :key="`emb_${field}`"
                                        class="grid gap-2"
                                    >
                                        <Label :for="`emb_cred_${field}`">{{
                                            credentialFieldLabel(field)
                                        }}</Label>
                                        <Input
                                            :id="`emb_cred_${field}`"
                                            v-model="
                                                createForm.embeddings.credentials[field]
                                            "
                                            :type="
                                                field === 'api_key' ? 'password' : 'text'
                                            "
                                        />
                                        <InputError
                                            :message="
                                                (createForm.errors as any)[
                                                    `embeddings.credentials.${field}`
                                                ]
                                            "
                                        />
                                    </div>
                                </template>
                            </template>

                            <div
                                v-if="
                                    createForm.embeddings.driver ||
                                    createForm.embeddings.same_as_llm
                                "
                                class="grid gap-2"
                            >
                                <Label for="embeddings_model">{{
                                    t('system.ai_provider_form.model')
                                }}</Label>
                                <Select v-model="createForm.embeddings.model_id">
                                    <SelectTrigger id="embeddings_model">
                                        <SelectValue
                                            :placeholder="
                                                t(
                                                    'system.ai_provider_form.select_model',
                                                )
                                            "
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="model in embeddingsModels"
                                            :key="model.id"
                                            :value="model.id"
                                        >
                                            {{ model.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="
                                        (createForm.errors as any)['embeddings.model_id']
                                    "
                                />
                            </div>
                        </div>
                    </section>

                    <!-- Actions -->
                    <div class="flex justify-end gap-4">
                        <Button variant="outline" as-child>
                            <Link href="/system/ai-providers">{{
                                t('common.cancel')
                            }}</Link>
                        </Button>
                        <Button
                            type="submit"
                            :disabled="createForm.processing || !canSubmitCreate"
                        >
                            {{ t('system.ai_provider_form.save') }}
                        </Button>
                    </div>
                </form>

                <!-- =========================== EDIT MODE =========================== -->
                <form
                    v-else
                    class="mt-8 space-y-6"
                    @submit.prevent="submitEdit"
                >
                    <!-- Display name -->
                    <div class="grid gap-2">
                        <Label for="display_name">{{
                            t('system.ai_provider_form.display_name')
                        }}</Label>
                        <Input
                            id="display_name"
                            v-model="editForm.display_name"
                            placeholder="e.g. Anthropic"
                        />
                        <InputError :message="editForm.errors.display_name" />
                    </div>

                    <!-- Credentials -->
                    <div class="space-y-4">
                        <Label class="text-base font-medium">{{
                            t('system.ai_provider_form.credentials')
                        }}</Label>
                        <div
                            v-for="field in editCredentialFields"
                            :key="field"
                            class="grid gap-2"
                        >
                            <Label :for="`cred_${field}`">{{
                                credentialFieldLabel(field)
                            }}</Label>
                            <Input
                                :id="`cred_${field}`"
                                v-model="editForm.credentials[field]"
                                :type="field === 'api_key' ? 'password' : 'text'"
                                :placeholder="
                                    field === 'api_key'
                                        ? 'Leave blank to keep current key'
                                        : ''
                                "
                            />
                            <InputError
                                :message="
                                    (editForm.errors as any)[`credentials.${field}`]
                                "
                            />
                        </div>
                    </div>

                    <!-- Models -->
                    <div class="space-y-4">
                        <div>
                            <Label class="text-base font-medium"
                                >Available Models</Label
                            >
                            <p class="text-sm text-muted-foreground">
                                Select which models to make available for agent creation
                            </p>
                        </div>

                        <div v-if="editCatalogModels.length > 0" class="space-y-2">
                            <div
                                v-for="model in editCatalogModels"
                                :key="model.id"
                                class="flex items-center gap-3 rounded-md border p-3"
                            >
                                <Checkbox
                                    :id="`model_${model.id}`"
                                    :checked="isEditModelSelected(model.id)"
                                    @update:checked="toggleEditModel(model)"
                                />
                                <label
                                    :for="`model_${model.id}`"
                                    class="flex-1 cursor-pointer"
                                >
                                    <div class="text-sm font-medium">
                                        {{ model.label }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ model.id }} &middot;
                                        {{ model.capabilities.join(', ') }}
                                    </div>
                                </label>
                            </div>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">
                            No predefined models for this provider.
                        </p>
                    </div>

                    <!-- Defaults -->
                    <div class="space-y-4">
                        <div
                            class="flex items-center justify-between rounded-md border p-3"
                        >
                            <div>
                                <div class="text-sm font-medium">
                                    Default LLM Provider
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    Use this provider by default for agent chat
                                </div>
                            </div>
                            <Switch v-model:checked="editForm.is_default" />
                        </div>

                        <div
                            class="flex items-center justify-between rounded-md border p-3"
                        >
                            <div>
                                <div class="text-sm font-medium">
                                    Default Embeddings Provider
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    Use this provider by default for document embeddings
                                </div>
                            </div>
                            <Switch
                                v-model:checked="editForm.is_default_embeddings"
                            />
                        </div>
                    </div>

                    <!-- Status -->
                    <div class="grid gap-2">
                        <Label for="status">Status</Label>
                        <Select v-model="editForm.status">
                            <SelectTrigger id="status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="active">{{
                                    t('common.active')
                                }}</SelectItem>
                                <SelectItem value="inactive">{{
                                    t('common.inactive')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end gap-4">
                        <Button variant="outline" as-child>
                            <Link href="/system/ai-providers">{{
                                t('common.cancel')
                            }}</Link>
                        </Button>
                        <Button type="submit" :disabled="editForm.processing">
                            {{ t('system.ai_provider_form.update') }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
