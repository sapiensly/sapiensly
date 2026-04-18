<script setup lang="ts">
import AdminDashboardController from '@/actions/App/Http/Controllers/Admin/AdminDashboardController';
import * as GlobalAiController from '@/actions/App/Http/Controllers/Admin/GlobalAiController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Bot,
    CheckCircle2,
    Database,
    Loader2,
    Pencil,
    Plug,
    Plus,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
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

interface DriverBasic {
    value: string;
    label: string;
}

interface ExistingSlot {
    driver: string;
    display_name: string;
    model_id: string | null;
    model_label: string | null;
    masked_credentials: Record<string, string>;
}

interface CatalogRow {
    id: number;
    driver: string;
    driver_label: string;
    model_id: string;
    label: string;
    capability: 'chat' | 'embeddings';
    is_enabled: boolean;
    sort_order: number;
    available_since: string | null;
}

interface Props {
    drivers: DriverOption[];
    driverOptions: DriverBasic[];
    existing: {
        llm: ExistingSlot | null;
        embeddings: ExistingSlot | null;
    };
    catalog: {
        chat: CatalogRow[];
        embeddings: CatalogRow[];
    };
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Admin', href: AdminDashboardController().url },
    { title: t('admin.global_ai.title'), href: GlobalAiController.index().url },
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

const emptyCredentials = (): Record<string, string> => ({ api_key: '' });

const activeTab = ref<'catalog' | 'defaults'>('catalog');
const showActiveOnly = ref(true);
const providerFilter = ref<string>('all');

const catalogProviderOptions = computed<DriverBasic[]>(() => {
    const seen = new Map<string, string>();
    [...props.catalog.chat, ...props.catalog.embeddings].forEach((row) => {
        if (!seen.has(row.driver)) {
            seen.set(row.driver, row.driver_label);
        }
    });
    return Array.from(seen, ([value, label]) => ({ value, label })).sort(
        (a, b) => a.label.localeCompare(b.label),
    );
});

const applyFilters = (rows: CatalogRow[]): CatalogRow[] =>
    rows.filter((row) => {
        if (showActiveOnly.value && !row.is_enabled) return false;
        if (providerFilter.value !== 'all' && row.driver !== providerFilter.value) {
            return false;
        }
        return true;
    });

const visibleChatCatalog = computed<CatalogRow[]>(() =>
    applyFilters(props.catalog.chat),
);

const visibleEmbeddingsCatalog = computed<CatalogRow[]>(() =>
    applyFilters(props.catalog.embeddings),
);

// =====================================================================
// DEFAULTS TAB — form
// =====================================================================
const initialSameAsLlm =
    !!props.existing.llm &&
    !!props.existing.embeddings &&
    props.existing.llm.driver === props.existing.embeddings.driver;

const form = useForm({
    llm: {
        driver: props.existing.llm?.driver ?? '',
        model_id: props.existing.llm?.model_id ?? '',
        credentials: emptyCredentials(),
    },
    embeddings: {
        driver: initialSameAsLlm ? '' : (props.existing.embeddings?.driver ?? ''),
        model_id: props.existing.embeddings?.model_id ?? '',
        credentials: emptyCredentials(),
        same_as_llm: initialSameAsLlm,
    },
});

const selectedLlmDriver = computed(() =>
    props.drivers.find((d) => d.value === form.llm.driver),
);

const selectedEmbeddingsDriver = computed(() => {
    if (form.embeddings.same_as_llm) {
        return selectedLlmDriver.value;
    }
    return props.drivers.find((d) => d.value === form.embeddings.driver);
});

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
    () => form.llm.driver,
    (driver, previous) => {
        if (driver === previous) return;
        form.llm.model_id = '';
        form.llm.credentials = emptyCredentials();

        if (form.embeddings.same_as_llm) {
            if (!driver || !llmDriverHasEmbeddings.value) {
                form.embeddings.same_as_llm = false;
            } else {
                form.embeddings.model_id = '';
            }
        }
    },
);

watch(
    () => form.embeddings.same_as_llm,
    (same) => {
        if (same) {
            if (!llmDriverHasEmbeddings.value) {
                form.embeddings.same_as_llm = false;
                return;
            }
            form.embeddings.driver = '';
            form.embeddings.model_id = '';
            form.embeddings.credentials = emptyCredentials();
        } else {
            form.embeddings.driver = '';
            form.embeddings.model_id = '';
            form.embeddings.credentials = emptyCredentials();
        }
    },
);

watch(
    () => form.embeddings.driver,
    () => {
        form.embeddings.model_id = '';
    },
);

const canSubmit = computed<boolean>(() => {
    const llmOk =
        form.llm.driver && form.llm.model_id && !!form.llm.credentials.api_key;

    const embeddingsOk = form.embeddings.same_as_llm
        ? !!form.embeddings.model_id
        : form.embeddings.driver &&
          form.embeddings.model_id &&
          !!form.embeddings.credentials.api_key;

    return Boolean(llmOk && embeddingsOk);
});

const submit = () => {
    form.transform((data) => {
        const embeddingsPayload = data.embeddings.same_as_llm
            ? {
                  driver: data.llm.driver,
                  model_id: data.embeddings.model_id,
                  credentials: data.llm.credentials,
              }
            : {
                  driver: data.embeddings.driver,
                  model_id: data.embeddings.model_id,
                  credentials: data.embeddings.credentials,
              };

        return {
            llm: {
                driver: data.llm.driver,
                model_id: data.llm.model_id,
                credentials: data.llm.credentials,
            },
            embeddings: embeddingsPayload,
        };
    }).post(GlobalAiController.store().url);
};

const existingSlotSummary = (slot: ExistingSlot | null): string => {
    if (!slot) return '';
    const name = slot.display_name ?? slot.driver;
    const model = slot.model_label ?? slot.model_id ?? '';
    return model ? `${name} · ${model}` : name;
};

// =====================================================================
// Test connection (LLM + Embeddings)
// =====================================================================
type TestState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'success'; message: string }
    | { status: 'error'; message: string };

const llmTestState = ref<TestState>({ status: 'idle' });
const embeddingsTestState = ref<TestState>({ status: 'idle' });

const canTestLlm = computed<boolean>(() => {
    // Either typing new credentials, or there's a saved global we can test.
    if (!!form.llm.driver && !!form.llm.credentials.api_key) return true;
    return !!props.existing.llm;
});

const canTestEmbeddings = computed<boolean>(() => {
    if (form.embeddings.same_as_llm) return canTestLlm.value;
    if (!!form.embeddings.driver && !!form.embeddings.credentials.api_key) return true;
    return !!props.existing.embeddings;
});

type TestPayload =
    | { slot: 'llm' | 'embeddings' }
    | {
          driver: string;
          credentials: Record<string, string>;
          model_id: string | null;
      };

async function runTest(state: typeof llmTestState, payload: TestPayload) {
    state.value = { status: 'loading' };
    try {
        const { data } = await axios.post(
            '/admin/system/global-ai/test-connection',
            payload,
        );
        state.value = data.success
            ? {
                  status: 'success',
                  message: data.message || t('system.ai_provider_form.test_success'),
              }
            : {
                  status: 'error',
                  message:
                      data.detail ||
                      data.message ||
                      t('system.ai_provider_form.test_failed'),
              };
    } catch (e: unknown) {
        const err = e as {
            response?: { data?: { message?: string; detail?: string } };
        };
        state.value = {
            status: 'error',
            message:
                err.response?.data?.detail ||
                err.response?.data?.message ||
                t('system.ai_provider_form.test_failed'),
        };
    }
}

const testLlmConnection = () => {
    // If the admin is typing new credentials, test them; otherwise fall back
    // to the saved global provider for that slot.
    if (form.llm.driver && form.llm.credentials.api_key) {
        runTest(llmTestState, {
            driver: form.llm.driver,
            credentials: form.llm.credentials,
            model_id: form.llm.model_id || null,
        });
        return;
    }
    runTest(llmTestState, { slot: 'llm' });
};

const testEmbeddingsConnection = () => {
    if (form.embeddings.same_as_llm) {
        if (form.llm.driver && form.llm.credentials.api_key) {
            runTest(embeddingsTestState, {
                driver: form.llm.driver,
                credentials: form.llm.credentials,
                model_id: form.embeddings.model_id || null,
            });
            return;
        }
        runTest(embeddingsTestState, { slot: 'embeddings' });
        return;
    }

    if (form.embeddings.driver && form.embeddings.credentials.api_key) {
        runTest(embeddingsTestState, {
            driver: form.embeddings.driver,
            credentials: form.embeddings.credentials,
            model_id: form.embeddings.model_id || null,
        });
        return;
    }
    runTest(embeddingsTestState, { slot: 'embeddings' });
};

// Reset test status when the underlying inputs change
watch(
    [() => form.llm.driver, () => form.llm.credentials.api_key],
    () => {
        llmTestState.value = { status: 'idle' };
    },
);

watch(
    [
        () => form.embeddings.driver,
        () => form.embeddings.credentials.api_key,
        () => form.embeddings.same_as_llm,
    ],
    () => {
        embeddingsTestState.value = { status: 'idle' };
    },
);

// =====================================================================
// CATALOG TAB — CRUD
// =====================================================================
const catalogDialogOpen = ref(false);
const catalogEditing = ref<CatalogRow | null>(null);

const catalogForm = useForm({
    driver: '',
    model_id: '',
    label: '',
    capability: 'chat' as 'chat' | 'embeddings',
    is_enabled: true,
});

const openCatalogCreate = (capability: 'chat' | 'embeddings') => {
    catalogEditing.value = null;
    catalogForm.reset();
    catalogForm.clearErrors();
    catalogForm.capability = capability;
    catalogForm.is_enabled = true;
    catalogDialogOpen.value = true;
};

const openCatalogEdit = (row: CatalogRow) => {
    catalogEditing.value = row;
    catalogForm.clearErrors();
    catalogForm.driver = row.driver;
    catalogForm.model_id = row.model_id;
    catalogForm.label = row.label;
    catalogForm.capability = row.capability;
    catalogForm.is_enabled = row.is_enabled;
    catalogDialogOpen.value = true;
};

const saveCatalogEntry = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            catalogDialogOpen.value = false;
        },
    };

    if (catalogEditing.value) {
        catalogForm.patch(
            `/admin/system/global-ai/catalog/${catalogEditing.value.id}`,
            options,
        );
    } else {
        catalogForm.post('/admin/system/global-ai/catalog', options);
    }
};

const toggleCatalogEnabled = (row: CatalogRow) => {
    router.patch(
        `/admin/system/global-ai/catalog/${row.id}`,
        {
            driver: row.driver,
            model_id: row.model_id,
            label: row.label,
            capability: row.capability,
            is_enabled: !row.is_enabled,
        },
        { preserveScroll: true },
    );
};

const deleteCatalogRow = (row: CatalogRow) => {
    if (!confirm(t('admin.global_ai.catalog.delete_confirm'))) return;
    router.delete(`/admin/system/global-ai/catalog/${row.id}`, {
        preserveScroll: true,
    });
};

const formatAvailableSince = (iso: string | null): string => {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};
</script>

<template>
    <Head :title="t('admin.global_ai.title')" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <Heading
                    :title="t('admin.global_ai.title')"
                    :description="t('admin.global_ai.description')"
                />

                <Tabs v-model="activeTab" class="mt-6">
                    <TabsList class="grid w-full max-w-md grid-cols-2">
                        <TabsTrigger value="catalog">
                            {{ t('admin.global_ai.tab_catalog') }}
                        </TabsTrigger>
                        <TabsTrigger value="defaults">
                            {{ t('admin.global_ai.tab_defaults') }}
                        </TabsTrigger>
                    </TabsList>

                    <!-- ============================= CATALOG TAB ============================= -->
                    <TabsContent value="catalog" class="mt-6 space-y-8">
                        <div class="flex flex-wrap items-center gap-4">
                            <div class="flex items-center gap-2">
                                <Checkbox
                                    id="show_active_only"
                                    :model-value="showActiveOnly"
                                    @update:model-value="showActiveOnly = $event === true"
                                />
                                <label
                                    for="show_active_only"
                                    class="cursor-pointer text-sm"
                                >
                                    {{ t('admin.global_ai.catalog.show_active_only') }}
                                </label>
                            </div>

                            <div class="flex items-center gap-2">
                                <Label
                                    for="provider_filter"
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ t('admin.global_ai.catalog.filter_provider') }}
                                </Label>
                                <Select v-model="providerFilter">
                                    <SelectTrigger id="provider_filter" class="w-56">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            {{ t('admin.global_ai.catalog.all_providers') }}
                                        </SelectItem>
                                        <SelectItem
                                            v-for="option in catalogProviderOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <section>
                            <div class="mb-4 flex items-center justify-between">
                                <div>
                                    <h3 class="text-base font-semibold">
                                        {{ t('admin.global_ai.catalog.llm_heading') }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ t('admin.global_ai.catalog.description') }}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="openCatalogCreate('chat')"
                                >
                                    <Plus class="mr-2 h-4 w-4" />
                                    {{ t('admin.global_ai.catalog.add') }}
                                </Button>
                            </div>

                            <Card v-if="visibleChatCatalog.length === 0">
                                <CardContent class="py-8 text-center text-sm text-muted-foreground">
                                    {{ t('admin.global_ai.catalog.empty_llm') }}
                                </CardContent>
                            </Card>

                            <div v-else class="divide-y rounded-md border">
                                <div
                                    v-for="row in visibleChatCatalog"
                                    :key="row.id"
                                    class="flex items-center gap-4 px-4 py-3"
                                >
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted">
                                        <Bot class="h-4 w-4 text-muted-foreground" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-medium">
                                                {{ row.label }}
                                            </span>
                                            <Badge variant="secondary" class="text-xs">
                                                {{ row.driver_label }}
                                            </Badge>
                                        </div>
                                        <div class="truncate text-xs text-muted-foreground">
                                            {{ row.model_id }}
                                        </div>
                                        <div
                                            v-if="row.available_since"
                                            class="truncate text-xs text-muted-foreground"
                                        >
                                            {{ t('admin.global_ai.catalog.available_since') }}:
                                            {{ formatAvailableSince(row.available_since) }}
                                        </div>
                                    </div>
                                    <Switch
                                        :model-value="row.is_enabled"
                                        @update:model-value="toggleCatalogEnabled(row)"
                                    />
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        @click="openCatalogEdit(row)"
                                    >
                                        <Pencil class="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        @click="deleteCatalogRow(row)"
                                    >
                                        <Trash2 class="h-4 w-4 text-destructive" />
                                    </Button>
                                </div>
                            </div>
                        </section>

                        <section>
                            <div class="mb-4 flex items-center justify-between">
                                <div>
                                    <h3 class="text-base font-semibold">
                                        {{
                                            t('admin.global_ai.catalog.embeddings_heading')
                                        }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ t('admin.global_ai.catalog.description') }}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="openCatalogCreate('embeddings')"
                                >
                                    <Plus class="mr-2 h-4 w-4" />
                                    {{ t('admin.global_ai.catalog.add') }}
                                </Button>
                            </div>

                            <Card v-if="visibleEmbeddingsCatalog.length === 0">
                                <CardContent class="py-8 text-center text-sm text-muted-foreground">
                                    {{ t('admin.global_ai.catalog.empty_embeddings') }}
                                </CardContent>
                            </Card>

                            <div v-else class="divide-y rounded-md border">
                                <div
                                    v-for="row in visibleEmbeddingsCatalog"
                                    :key="row.id"
                                    class="flex items-center gap-4 px-4 py-3"
                                >
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted">
                                        <Database class="h-4 w-4 text-muted-foreground" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-medium">
                                                {{ row.label }}
                                            </span>
                                            <Badge variant="secondary" class="text-xs">
                                                {{ row.driver_label }}
                                            </Badge>
                                        </div>
                                        <div class="truncate text-xs text-muted-foreground">
                                            {{ row.model_id }}
                                        </div>
                                        <div
                                            v-if="row.available_since"
                                            class="truncate text-xs text-muted-foreground"
                                        >
                                            {{ t('admin.global_ai.catalog.available_since') }}:
                                            {{ formatAvailableSince(row.available_since) }}
                                        </div>
                                    </div>
                                    <Switch
                                        :model-value="row.is_enabled"
                                        @update:model-value="toggleCatalogEnabled(row)"
                                    />
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        @click="openCatalogEdit(row)"
                                    >
                                        <Pencil class="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        @click="deleteCatalogRow(row)"
                                    >
                                        <Trash2 class="h-4 w-4 text-destructive" />
                                    </Button>
                                </div>
                            </div>
                        </section>
                    </TabsContent>

                    <!-- ============================= DEFAULTS TAB ============================= -->
                    <TabsContent value="defaults" class="mt-6">
                        <!-- Currently configured summary -->
                        <div
                            v-if="existing.llm || existing.embeddings"
                            class="grid gap-3 sm:grid-cols-2"
                        >
                            <Card>
                                <CardContent class="pt-6">
                                    <div class="flex items-start gap-3">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                            <Bot class="h-5 w-5" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <p class="text-sm font-medium">
                                                    {{
                                                        t(
                                                            'system.ai_provider_form.default_llm',
                                                        )
                                                    }}
                                                </p>
                                                <Badge
                                                    v-if="existing.llm"
                                                    variant="default"
                                                    class="text-xs"
                                                >
                                                    {{ t('admin.global_ai.active') }}
                                                </Badge>
                                            </div>
                                            <p class="mt-1 truncate text-xs text-muted-foreground">
                                                {{
                                                    existing.llm
                                                        ? existingSlotSummary(existing.llm)
                                                        : t('admin.global_ai.not_configured')
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent class="pt-6">
                                    <div class="flex items-start gap-3">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                            <Database class="h-5 w-5" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <p class="text-sm font-medium">
                                                    {{
                                                        t(
                                                            'system.ai_provider_form.default_embeddings',
                                                        )
                                                    }}
                                                </p>
                                                <Badge
                                                    v-if="existing.embeddings"
                                                    variant="default"
                                                    class="text-xs"
                                                >
                                                    {{ t('admin.global_ai.active') }}
                                                </Badge>
                                            </div>
                                            <p class="mt-1 truncate text-xs text-muted-foreground">
                                                {{
                                                    existing.embeddings
                                                        ? existingSlotSummary(existing.embeddings)
                                                        : t('admin.global_ai.not_configured')
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        <form class="mt-8 space-y-6" @submit.prevent="submit">
                            <!-- Default LLM section -->
                            <section class="rounded-lg border p-5">
                                <div class="mb-4 flex items-start gap-3">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
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
                                        <Select v-model="form.llm.driver">
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
                                            :message="(form.errors as any)['llm.driver']"
                                        />
                                    </div>

                                    <template v-if="form.llm.driver">
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
                                                v-model="form.llm.credentials[field]"
                                                :type="field === 'api_key' ? 'password' : 'text'"
                                                :placeholder="
                                                    existing.llm && field === 'api_key'
                                                        ? existing.llm.masked_credentials.api_key
                                                        : ''
                                                "
                                            />
                                            <InputError
                                                :message="
                                                    (form.errors as any)[
                                                        `llm.credentials.${field}`
                                                    ]
                                                "
                                            />
                                        </div>

                                        <div class="grid gap-2">
                                            <Label for="llm_model">{{
                                                t('system.ai_provider_form.model')
                                            }}</Label>
                                            <Select v-model="form.llm.model_id">
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
                                                :message="(form.errors as any)['llm.model_id']"
                                            />
                                        </div>

                                        <div class="flex flex-col gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                class="self-start"
                                                :disabled="
                                                    !canTestLlm ||
                                                    llmTestState.status === 'loading'
                                                "
                                                @click="testLlmConnection"
                                            >
                                                <Loader2
                                                    v-if="llmTestState.status === 'loading'"
                                                    class="mr-2 h-4 w-4 animate-spin"
                                                />
                                                <Plug v-else class="mr-2 h-4 w-4" />
                                                {{
                                                    llmTestState.status === 'loading'
                                                        ? t('system.ai_provider_form.testing')
                                                        : t(
                                                              'system.ai_provider_form.test_connection',
                                                          )
                                                }}
                                            </Button>
                                            <div
                                                v-if="llmTestState.status === 'success'"
                                                class="flex items-start gap-2 rounded-md border border-green-200 bg-green-50 p-2 text-xs text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300"
                                            >
                                                <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
                                                <span>{{ llmTestState.message }}</span>
                                            </div>
                                            <div
                                                v-else-if="llmTestState.status === 'error'"
                                                class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
                                            >
                                                <XCircle class="mt-0.5 h-4 w-4 shrink-0" />
                                                <span>{{ llmTestState.message }}</span>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </section>

                            <!-- Default Embeddings section -->
                            <section class="rounded-lg border p-5">
                                <div class="mb-4 flex items-start gap-3">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
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
                                        v-if="form.llm.driver && llmDriverHasEmbeddings"
                                        class="flex items-center gap-3 rounded-md border border-dashed p-3"
                                    >
                                        <Checkbox
                                            id="same_as_llm"
                                            :checked="form.embeddings.same_as_llm"
                                            @update:checked="
                                                form.embeddings.same_as_llm = $event
                                            "
                                        />
                                        <label for="same_as_llm" class="cursor-pointer text-sm">
                                            {{ t('system.ai_provider_form.same_as_llm') }}
                                        </label>
                                    </div>

                                    <template v-if="!form.embeddings.same_as_llm">
                                        <div class="grid gap-2">
                                            <Label for="embeddings_driver">{{
                                                t('system.ai_provider_form.provider')
                                            }}</Label>
                                            <Select v-model="form.embeddings.driver">
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
                                                :message="(form.errors as any)['embeddings.driver']"
                                            />
                                        </div>

                                        <template v-if="form.embeddings.driver">
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
                                                    v-model="form.embeddings.credentials[field]"
                                                    :type="field === 'api_key' ? 'password' : 'text'"
                                                    :placeholder="
                                                        existing.embeddings && field === 'api_key'
                                                            ? existing.embeddings.masked_credentials.api_key
                                                            : ''
                                                    "
                                                />
                                                <InputError
                                                    :message="
                                                        (form.errors as any)[
                                                            `embeddings.credentials.${field}`
                                                        ]
                                                    "
                                                />
                                            </div>
                                        </template>
                                    </template>

                                    <div
                                        v-if="
                                            form.embeddings.same_as_llm ||
                                            form.embeddings.driver
                                        "
                                        class="grid gap-2"
                                    >
                                        <Label for="embeddings_model">{{
                                            t('system.ai_provider_form.model')
                                        }}</Label>
                                        <Select v-model="form.embeddings.model_id">
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
                                            :message="(form.errors as any)['embeddings.model_id']"
                                        />
                                    </div>

                                    <div
                                        v-if="
                                            form.embeddings.same_as_llm ||
                                            form.embeddings.driver
                                        "
                                        class="flex flex-col gap-2"
                                    >
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="self-start"
                                            :disabled="
                                                !canTestEmbeddings ||
                                                embeddingsTestState.status === 'loading'
                                            "
                                            @click="testEmbeddingsConnection"
                                        >
                                            <Loader2
                                                v-if="
                                                    embeddingsTestState.status === 'loading'
                                                "
                                                class="mr-2 h-4 w-4 animate-spin"
                                            />
                                            <Plug v-else class="mr-2 h-4 w-4" />
                                            {{
                                                embeddingsTestState.status === 'loading'
                                                    ? t('system.ai_provider_form.testing')
                                                    : t(
                                                          'system.ai_provider_form.test_connection',
                                                      )
                                            }}
                                        </Button>
                                        <div
                                            v-if="embeddingsTestState.status === 'success'"
                                            class="flex items-start gap-2 rounded-md border border-green-200 bg-green-50 p-2 text-xs text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300"
                                        >
                                            <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
                                            <span>{{ embeddingsTestState.message }}</span>
                                        </div>
                                        <div
                                            v-else-if="embeddingsTestState.status === 'error'"
                                            class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
                                        >
                                            <XCircle class="mt-0.5 h-4 w-4 shrink-0" />
                                            <span>{{ embeddingsTestState.message }}</span>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <div class="flex justify-end">
                                <Button
                                    type="submit"
                                    :disabled="form.processing || !canSubmit"
                                >
                                    {{ t('system.ai_provider_form.save') }}
                                </Button>
                            </div>
                        </form>
                    </TabsContent>
                </Tabs>
            </div>
        </div>

        <!-- Catalog dialog -->
        <Dialog v-model:open="catalogDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            catalogEditing
                                ? t('admin.global_ai.catalog.edit_title')
                                : t('admin.global_ai.catalog.new_title')
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ t('admin.global_ai.catalog.description') }}
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="saveCatalogEntry">
                    <div class="grid gap-2">
                        <Label for="catalog_driver">{{
                            t('system.ai_provider_form.provider')
                        }}</Label>
                        <Select v-model="catalogForm.driver">
                            <SelectTrigger id="catalog_driver">
                                <SelectValue
                                    :placeholder="
                                        t('system.ai_provider_form.select_provider')
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="driver in driverOptions"
                                    :key="driver.value"
                                    :value="driver.value"
                                >
                                    {{ driver.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="catalogForm.errors.driver" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="catalog_capability">{{
                            t('admin.global_ai.catalog.capability')
                        }}</Label>
                        <Select v-model="catalogForm.capability">
                            <SelectTrigger id="catalog_capability">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="chat">
                                    {{ t('admin.global_ai.catalog.capability_chat') }}
                                </SelectItem>
                                <SelectItem value="embeddings">
                                    {{
                                        t(
                                            'admin.global_ai.catalog.capability_embeddings',
                                        )
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="catalogForm.errors.capability" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="catalog_model_id">{{
                            t('admin.global_ai.catalog.model_id')
                        }}</Label>
                        <Input
                            id="catalog_model_id"
                            v-model="catalogForm.model_id"
                            :placeholder="
                                t('admin.global_ai.catalog.model_id_placeholder')
                            "
                        />
                        <InputError :message="catalogForm.errors.model_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="catalog_label">{{
                            t('system.ai_provider_form.display_name')
                        }}</Label>
                        <Input
                            id="catalog_label"
                            v-model="catalogForm.label"
                            :placeholder="
                                t('admin.global_ai.catalog.label_placeholder')
                            "
                        />
                        <InputError :message="catalogForm.errors.label" />
                    </div>

                    <div class="flex items-center justify-between rounded-md border p-3">
                        <Label for="catalog_enabled" class="cursor-pointer text-sm">
                            {{ t('admin.global_ai.catalog.enabled') }}
                        </Label>
                        <Switch
                            id="catalog_enabled"
                            :model-value="catalogForm.is_enabled"
                            @update:model-value="catalogForm.is_enabled = $event"
                        />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="catalogDialogOpen = false"
                        >
                            {{ t('common.cancel') }}
                        </Button>
                        <Button type="submit" :disabled="catalogForm.processing">
                            {{ t('system.ai_provider_form.save') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
