<script setup lang="ts">
import SettingsCard from '@/components/admin/SettingsCard.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import InputError from '@/components/InputError.vue';
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
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Bot, Database, Key, Layers, Power, Sparkles } from 'lucide-vue-next';
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

watch(
    () => createForm.llm.driver,
    (driver) => {
        createForm.llm.model_id = '';
        createForm.llm.credentials = emptyCredentials();

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
    if (createForm.embeddings.same_as_llm) {
        createForm.embeddings.driver = createForm.llm.driver;
        createForm.embeddings.credentials = { ...createForm.llm.credentials };
    }

    createForm
        .transform((data) => ({
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
        }))
        .post('/system/ai-providers');
};

// =====================================================================
// EDIT MODE — single provider form
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

    <AppLayoutV2 :title="t('app_v2.nav.ai_providers')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
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
                class="space-y-4"
                @submit.prevent="submitCreate"
            >
                <!-- Default LLM. -->
                <SettingsCard
                    :icon="Bot"
                    :title="t('system.ai_provider_form.default_llm')"
                    :description="t('system.ai_provider_form.default_llm_description')"
                >
                    <div class="space-y-1.5">
                        <Label for="llm_driver">
                            {{ t('system.ai_provider_form.provider') }}
                        </Label>
                        <Select v-model="createForm.llm.driver">
                            <SelectTrigger id="llm_driver" class="h-9">
                                <SelectValue
                                    :placeholder="t('system.ai_provider_form.select_provider')"
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
                        <InputError :message="(createForm.errors as any)['llm.driver']" />
                    </div>

                    <template v-if="createForm.llm.driver">
                        <div
                            v-for="field in llmCredentialFields"
                            :key="`llm_${field}`"
                            class="space-y-1.5"
                        >
                            <Label :for="`llm_cred_${field}`">
                                {{ credentialFieldLabel(field) }}
                            </Label>
                            <Input
                                :id="`llm_cred_${field}`"
                                v-model="createForm.llm.credentials[field]"
                                :type="field === 'api_key' ? 'password' : 'text'"
                                class="h-9"
                            />
                            <InputError
                                :message="(createForm.errors as any)[`llm.credentials.${field}`]"
                            />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="llm_model">
                                {{ t('system.ai_provider_form.model') }}
                            </Label>
                            <Select v-model="createForm.llm.model_id">
                                <SelectTrigger id="llm_model" class="h-9">
                                    <SelectValue
                                        :placeholder="t('system.ai_provider_form.select_model')"
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
                            <InputError :message="(createForm.errors as any)['llm.model_id']" />
                        </div>
                    </template>
                </SettingsCard>

                <!-- Default Embeddings. -->
                <SettingsCard
                    :icon="Database"
                    :title="t('system.ai_provider_form.default_embeddings')"
                    :description="t('system.ai_provider_form.default_embeddings_description')"
                    tint="var(--sp-spectrum-magenta)"
                >
                    <!-- "Same as LLM" shortcut — only when the LLM driver also ships embeddings. -->
                    <label
                        v-if="createForm.llm.driver && llmDriverHasEmbeddings"
                        for="same_as_llm"
                        class="flex cursor-pointer items-center gap-3 rounded-xs border border-dashed border-soft bg-white/[0.03] px-3 py-2.5 text-sm text-ink transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
                    >
                        <Checkbox
                            id="same_as_llm"
                            :checked="createForm.embeddings.same_as_llm"
                            @update:checked="
                                createForm.embeddings.same_as_llm = $event
                            "
                        />
                        <span>{{ t('system.ai_provider_form.same_as_llm') }}</span>
                    </label>

                    <template v-if="!createForm.embeddings.same_as_llm">
                        <div class="space-y-1.5">
                            <Label for="embeddings_driver">
                                {{ t('system.ai_provider_form.provider') }}
                            </Label>
                            <Select v-model="createForm.embeddings.driver">
                                <SelectTrigger id="embeddings_driver" class="h-9">
                                    <SelectValue
                                        :placeholder="t('system.ai_provider_form.select_provider')"
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
                            <InputError :message="(createForm.errors as any)['embeddings.driver']" />
                        </div>

                        <template v-if="createForm.embeddings.driver">
                            <div
                                v-for="field in embeddingsCredentialFields"
                                :key="`emb_${field}`"
                                class="space-y-1.5"
                            >
                                <Label :for="`emb_cred_${field}`">
                                    {{ credentialFieldLabel(field) }}
                                </Label>
                                <Input
                                    :id="`emb_cred_${field}`"
                                    v-model="createForm.embeddings.credentials[field]"
                                    :type="field === 'api_key' ? 'password' : 'text'"
                                    class="h-9"
                                />
                                <InputError
                                    :message="(createForm.errors as any)[`embeddings.credentials.${field}`]"
                                />
                            </div>
                        </template>
                    </template>

                    <div
                        v-if="createForm.embeddings.driver || createForm.embeddings.same_as_llm"
                        class="space-y-1.5"
                    >
                        <Label for="embeddings_model">
                            {{ t('system.ai_provider_form.model') }}
                        </Label>
                        <Select v-model="createForm.embeddings.model_id">
                            <SelectTrigger id="embeddings_model" class="h-9">
                                <SelectValue
                                    :placeholder="t('system.ai_provider_form.select_model')"
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
                        <InputError :message="(createForm.errors as any)['embeddings.model_id']" />
                    </div>
                </SettingsCard>

                <!-- Footer actions. -->
                <div class="flex items-center justify-end gap-2 pt-2">
                    <Link href="/system/ai-providers">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        >
                            {{ t('common.cancel') }}
                        </button>
                    </Link>
                    <button
                        type="submit"
                        :disabled="createForm.processing || !canSubmitCreate"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        {{ t('system.ai_provider_form.save') }}
                    </button>
                </div>
            </form>

            <!-- =========================== EDIT MODE =========================== -->
            <form
                v-else
                class="space-y-4"
                @submit.prevent="submitEdit"
            >
                <!-- Identity + credentials. -->
                <SettingsCard
                    :icon="Key"
                    :title="t('system.ai_provider_form.credentials')"
                    description="Display name and API keys"
                >
                    <div class="space-y-1.5">
                        <Label for="display_name">
                            {{ t('system.ai_provider_form.display_name') }}
                        </Label>
                        <Input
                            id="display_name"
                            v-model="editForm.display_name"
                            placeholder="e.g. Anthropic"
                            class="h-9"
                        />
                        <InputError :message="editForm.errors.display_name" />
                    </div>

                    <div
                        v-for="field in editCredentialFields"
                        :key="field"
                        class="space-y-1.5"
                    >
                        <Label :for="`cred_${field}`">
                            {{ credentialFieldLabel(field) }}
                        </Label>
                        <Input
                            :id="`cred_${field}`"
                            v-model="editForm.credentials[field]"
                            :type="field === 'api_key' ? 'password' : 'text'"
                            :placeholder="
                                field === 'api_key'
                                    ? 'Leave blank to keep current key'
                                    : ''
                            "
                            class="h-9"
                        />
                        <InputError
                            :message="(editForm.errors as any)[`credentials.${field}`]"
                        />
                    </div>
                </SettingsCard>

                <!-- Available models. -->
                <SettingsCard
                    :icon="Layers"
                    title="Available Models"
                    description="Select which models to make available for agent creation"
                    tint="var(--sp-accent-cyan)"
                >
                    <div v-if="editCatalogModels.length > 0" class="space-y-1.5">
                        <label
                            v-for="model in editCatalogModels"
                            :key="model.id"
                            :for="`model_${model.id}`"
                            class="flex cursor-pointer items-center gap-3 rounded-xs border border-soft bg-white/[0.03] px-3 py-2.5 transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
                        >
                            <Checkbox
                                :id="`model_${model.id}`"
                                :checked="isEditModelSelected(model.id)"
                                @update:checked="toggleEditModel(model)"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-ink">
                                    {{ model.label }}
                                </p>
                                <p class="text-[11px] text-ink-subtle">
                                    {{ model.id }} · {{ model.capabilities.join(', ') }}
                                </p>
                            </div>
                        </label>
                    </div>
                    <p v-else class="text-xs text-ink-muted">
                        No predefined models for this provider.
                    </p>
                </SettingsCard>

                <!-- Defaults. -->
                <SettingsCard
                    :icon="Sparkles"
                    title="Defaults"
                    description="Mark this provider as the default for chat or embeddings"
                    tint="var(--sp-spectrum-magenta)"
                >
                    <div
                        class="flex items-center justify-between gap-3 rounded-xs border border-soft bg-white/[0.03] p-3"
                    >
                        <div class="min-w-0 space-y-0.5">
                            <p class="text-sm font-medium text-ink">
                                Default LLM Provider
                            </p>
                            <p class="text-[11px] text-ink-subtle">
                                Use this provider by default for agent chat
                            </p>
                        </div>
                        <Switch v-model:checked="editForm.is_default" />
                    </div>

                    <div
                        class="flex items-center justify-between gap-3 rounded-xs border border-soft bg-white/[0.03] p-3"
                    >
                        <div class="min-w-0 space-y-0.5">
                            <p class="text-sm font-medium text-ink">
                                Default Embeddings Provider
                            </p>
                            <p class="text-[11px] text-ink-subtle">
                                Use this provider by default for document embeddings
                            </p>
                        </div>
                        <Switch v-model:checked="editForm.is_default_embeddings" />
                    </div>
                </SettingsCard>

                <!-- Status. -->
                <SettingsCard
                    :icon="Power"
                    title="Status"
                    description="Active providers appear in model pickers; inactive ones are hidden"
                >
                    <div class="space-y-1.5">
                        <Label for="status">Status</Label>
                        <Select v-model="editForm.status">
                            <SelectTrigger id="status" class="h-9">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="active">
                                    {{ t('common.active') }}
                                </SelectItem>
                                <SelectItem value="inactive">
                                    {{ t('common.inactive') }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </SettingsCard>

                <!-- Footer actions. -->
                <div class="flex items-center justify-end gap-2 pt-2">
                    <Link href="/system/ai-providers">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        >
                            {{ t('common.cancel') }}
                        </button>
                    </Link>
                    <button
                        type="submit"
                        :disabled="editForm.processing"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        {{ t('system.ai_provider_form.update') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
