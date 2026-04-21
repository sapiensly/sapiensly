<script setup lang="ts">
import * as CloudProviderController from '@/actions/App/Http/Controllers/CloudProviderController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import VectorStoreStatus from '@/components/VectorStoreStatus.vue';
import WipeConfirmDialog, { type WipeCounts } from '@/components/WipeConfirmDialog.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
    CheckCircle2,
    Cloud,
    Database,
    HardDrive,
    Info,
    Loader2,
    Plug,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface DriverOption {
    value: string;
    label: string;
    credential_fields: string[];
    optional_fields: string[];
}

interface ProviderSummary {
    id: string;
    driver: string;
    display_name: string;
    masked_credentials: Record<string, string | null>;
    status: string;
}

interface Props {
    canManage: boolean;
    drivers: {
        storage: DriverOption[];
        database: DriverOption[];
    };
    tenant: {
        storage: ProviderSummary | null;
        database: ProviderSummary | null;
    };
    global: {
        storage: ProviderSummary | null;
        database: ProviderSummary | null;
    };
}

const props = defineProps<Props>();

const activeTab = ref<'storage' | 'database'>('storage');

const fieldLabel = (field: string): string =>
    t(`system.cloud_providers.fields.${field}`, field);

const isSecret = (field: string): boolean =>
    ['secret', 'password', 'key'].includes(field);

const isPayloadComplete = (
    fields: string[],
    optional: string[],
    values: Record<string, string>,
): boolean => fields.every((f) => optional.includes(f) || !!values[f]);

// ============================================================================
// Storage form
// ============================================================================
const storageForm = useForm({
    driver: props.tenant.storage?.driver ?? '',
    credentials: {} as Record<string, string>,
});

const selectedStorageDriver = computed<DriverOption | undefined>(() =>
    props.drivers.storage.find((d) => d.value === storageForm.driver),
);

const storageFields = computed<string[]>(
    () => selectedStorageDriver.value?.credential_fields ?? [],
);

const storageOptionalFields = computed<string[]>(
    () => selectedStorageDriver.value?.optional_fields ?? [],
);

const canSubmitStorage = computed<boolean>(() =>
    !!storageForm.driver &&
    isPayloadComplete(storageFields.value, storageOptionalFields.value, storageForm.credentials),
);

watch(
    () => storageForm.driver,
    (driver, previous) => {
        if (driver === previous) return;
        storageForm.credentials = {};
    },
);

const submitStorage = () => {
    storageForm.post(CloudProviderController.storeStorage().url, {
        preserveScroll: true,
        onSuccess: () => {
            storageForm.credentials = {};
        },
    });
};

// ============================================================================
// Database form
// ============================================================================
const databaseForm = useForm({
    driver: props.tenant.database?.driver ?? 'postgresql',
    credentials: {} as Record<string, string>,
});

const selectedDatabaseDriver = computed<DriverOption | undefined>(() =>
    props.drivers.database.find((d) => d.value === databaseForm.driver),
);

const databaseFields = computed<string[]>(
    () => selectedDatabaseDriver.value?.credential_fields ?? [],
);

const databaseOptionalFields = computed<string[]>(
    () => selectedDatabaseDriver.value?.optional_fields ?? [],
);

const canSubmitDatabase = computed<boolean>(() =>
    !!databaseForm.driver &&
    isPayloadComplete(databaseFields.value, databaseOptionalFields.value, databaseForm.credentials),
);

watch(
    () => databaseForm.driver,
    (driver, previous) => {
        if (driver === previous) return;
        databaseForm.credentials = {};
    },
);

const vectorRefreshToken = ref(0);

const wipeDialogOpen = ref(false);
const wipeCounts = ref<WipeCounts | null>(null);
const wipePendingAction = ref<'save' | 'destroy' | null>(null);

const readFlashWipe = (): WipeCounts | undefined =>
    (usePage().props.flash as { wipe_required?: WipeCounts } | undefined)?.wipe_required;

const postDatabase = (withConfirm: boolean) => {
    databaseForm
        .transform((data) => (withConfirm ? { ...data, confirm: 'DELETE' } : data))
        .post(CloudProviderController.storeDatabase().url, {
            preserveScroll: true,
            onSuccess: () => {
                const wipe = readFlashWipe();
                if (wipe) {
                    wipeCounts.value = wipe;
                    wipePendingAction.value = 'save';
                    wipeDialogOpen.value = true;
                    return;
                }

                wipeDialogOpen.value = false;
                wipeCounts.value = null;
                databaseForm.credentials = {};
                vectorRefreshToken.value++;
            },
        });
};

const submitDatabase = () => {
    postDatabase(false);
};

// ============================================================================
// Remove override
// ============================================================================
const destroyDatabase = (withConfirm: boolean) => {
    router.delete(CloudProviderController.destroy({ kind: 'database' }).url, {
        data: withConfirm ? { confirm: 'DELETE' } : {},
        preserveScroll: true,
        onSuccess: () => {
            const wipe = readFlashWipe();
            if (wipe) {
                wipeCounts.value = wipe;
                wipePendingAction.value = 'destroy';
                wipeDialogOpen.value = true;
                return;
            }

            wipeDialogOpen.value = false;
            wipeCounts.value = null;
            vectorRefreshToken.value++;
        },
    });
};

const removeOverride = (kind: 'storage' | 'database') => {
    if (kind === 'database') {
        destroyDatabase(false);
        return;
    }

    if (!confirm(t('system.cloud_providers.remove_override_confirm'))) return;
    router.delete(CloudProviderController.destroy({ kind }).url, {
        preserveScroll: true,
    });
};

const confirmWipeAndProceed = () => {
    if (wipePendingAction.value === 'save') {
        postDatabase(true);
    } else if (wipePendingAction.value === 'destroy') {
        destroyDatabase(true);
    }
};

// ============================================================================
// Test connection
// ============================================================================
type TestState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'success'; message: string }
    | { status: 'error'; message: string };

const storageTestState = ref<TestState>({ status: 'idle' });
const databaseTestState = ref<TestState>({ status: 'idle' });

const canTestStorage = computed<boolean>(() => {
    if (
        !!storageForm.driver &&
        isPayloadComplete(storageFields.value, storageOptionalFields.value, storageForm.credentials)
    ) {
        return true;
    }
    return !!props.tenant.storage;
});

const canTestDatabase = computed<boolean>(() => {
    if (
        !!databaseForm.driver &&
        isPayloadComplete(databaseFields.value, databaseOptionalFields.value, databaseForm.credentials)
    ) {
        return true;
    }
    return !!props.tenant.database;
});

async function runTest(
    state: typeof storageTestState,
    url: string,
    payload: Record<string, unknown>,
): Promise<void> {
    state.value = { status: 'loading' };
    try {
        const { data } = await axios.post(url, payload);
        state.value = data.success
            ? { status: 'success', message: data.message || t('system.cloud_providers.test_success') }
            : {
                  status: 'error',
                  message:
                      data.detail ||
                      data.message ||
                      t('system.cloud_providers.test_failed'),
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
                t('system.cloud_providers.test_failed'),
        };
    }
}

const testStorageConnection = () => {
    if (
        storageForm.driver &&
        isPayloadComplete(storageFields.value, storageOptionalFields.value, storageForm.credentials)
    ) {
        runTest(storageTestState, CloudProviderController.testStorage().url, {
            driver: storageForm.driver,
            credentials: storageForm.credentials,
        });
        return;
    }
    runTest(storageTestState, CloudProviderController.testStorage().url, { use_saved: true });
};

const testDatabaseConnection = () => {
    if (
        databaseForm.driver &&
        isPayloadComplete(databaseFields.value, databaseOptionalFields.value, databaseForm.credentials)
    ) {
        runTest(databaseTestState, CloudProviderController.testDatabase().url, {
            driver: databaseForm.driver,
            credentials: databaseForm.credentials,
        });
        return;
    }
    runTest(databaseTestState, CloudProviderController.testDatabase().url, { use_saved: true });
};

watch(
    [() => storageForm.driver, () => storageForm.credentials],
    () => {
        storageTestState.value = { status: 'idle' };
    },
    { deep: true },
);

watch(
    [() => databaseForm.driver, () => databaseForm.credentials],
    () => {
        databaseTestState.value = { status: 'idle' };
    },
    { deep: true },
);
</script>

<template>
    <Head :title="t('system.cloud_providers.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.cloud_providers')">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader
                :title="t('system.cloud_providers.title')"
                :description="t('system.cloud_providers.description')"
            />

            <!-- Read-only notice for non-managers. -->
            <div
                v-if="!canManage"
                class="flex items-start gap-2 rounded-sp-sm border border-sp-warning/30 bg-sp-warning/10 px-4 py-3 text-sm text-sp-warning"
            >
                <Info class="mt-0.5 size-4 shrink-0" />
                <span>{{ t('system.cloud_providers.read_only_notice') }}</span>
            </div>

            <Tabs v-model="activeTab">
                <TabsList class="grid w-full max-w-md grid-cols-2">
                    <TabsTrigger value="storage">
                        {{ t('system.cloud_providers.tab_storage') }}
                    </TabsTrigger>
                    <TabsTrigger value="database">
                        {{ t('system.cloud_providers.tab_database') }}
                    </TabsTrigger>
                </TabsList>

                <!-- =========================== STORAGE TAB =========================== -->
                <TabsContent value="storage" class="mt-4 space-y-4">
                    <!-- Tenant override row. -->
                    <div
                        v-if="tenant.storage"
                        class="flex items-start gap-3 rounded-sp-sm border border-soft bg-navy p-5"
                    >
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-cyan/15 text-accent-cyan"
                        >
                            <HardDrive class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-semibold text-ink">
                                    {{ tenant.storage.display_name }}
                                </p>
                                <span
                                    class="inline-flex items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                                    style="color: var(--sp-success); border-color: color-mix(in oklab, var(--sp-success) 45%, transparent)"
                                >
                                    {{ t('system.cloud_providers.active') }}
                                </span>
                            </div>
                            <p
                                v-if="tenant.storage.masked_credentials.bucket"
                                class="mt-0.5 truncate text-xs text-ink-muted"
                            >
                                {{ tenant.storage.masked_credentials.bucket }}
                                <span v-if="tenant.storage.masked_credentials.region">
                                    · {{ tenant.storage.masked_credentials.region }}
                                </span>
                            </p>
                            <p class="mt-1 text-[11px] text-ink-subtle">
                                {{ t('system.cloud_providers.override_active_storage') }}
                            </p>
                        </div>
                        <button
                            v-if="canManage"
                            type="button"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3 py-1 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20"
                            @click="removeOverride('storage')"
                        >
                            <Trash2 class="size-3.5" />
                            {{ t('system.cloud_providers.remove_override') }}
                        </button>
                    </div>

                    <!-- Global fallback banner. -->
                    <div
                        v-else-if="global.storage"
                        class="flex items-start gap-2 rounded-sp-sm border border-accent-blue/30 bg-accent-blue/10 px-4 py-3 text-sm text-accent-blue"
                    >
                        <Info class="mt-0.5 size-4 shrink-0" />
                        <span>
                            {{
                                t('system.cloud_providers.using_global_storage', {
                                    provider: global.storage.display_name,
                                })
                            }}
                        </span>
                    </div>

                    <div
                        v-else
                        class="flex items-start gap-2 rounded-sp-sm border border-sp-danger/30 bg-sp-danger/10 px-4 py-3 text-sm text-sp-danger"
                    >
                        <Info class="mt-0.5 size-4 shrink-0" />
                        <span>{{ t('system.cloud_providers.using_global_storage_unconfigured') }}</span>
                    </div>

                    <!-- Storage config form. -->
                    <section
                        v-if="canManage"
                        class="rounded-sp-sm border border-soft bg-navy p-5"
                    >
                        <header class="mb-4 flex items-start gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-xs bg-accent-cyan/15 text-accent-cyan"
                            >
                                <Cloud class="size-4" />
                            </div>
                            <div>
                                <h3 class="text-sm font-medium text-ink">
                                    {{ t('system.cloud_providers.storage_heading') }}
                                </h3>
                                <p class="text-xs text-ink-muted">
                                    {{ t('system.cloud_providers.storage_description') }}
                                </p>
                            </div>
                        </header>

                        <form class="space-y-3" @submit.prevent="submitStorage">
                            <div class="space-y-1.5">
                                <Label for="tenant_storage_driver">
                                    {{ t('system.cloud_providers.provider') }}
                                </Label>
                                <Select v-model="storageForm.driver">
                                    <SelectTrigger
                                        id="tenant_storage_driver"
                                        class="h-9"
                                    >
                                        <SelectValue
                                            :placeholder="t('system.cloud_providers.select_provider')"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="driver in drivers.storage"
                                            :key="driver.value"
                                            :value="driver.value"
                                        >
                                            {{ driver.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="storageForm.errors.driver" />
                            </div>

                            <template v-if="storageForm.driver">
                                <div
                                    v-for="field in storageFields"
                                    :key="`tenant_storage_${field}`"
                                    class="space-y-1.5"
                                >
                                    <Label :for="`tenant_storage_${field}`">
                                        {{ fieldLabel(field) }}
                                        <span
                                            v-if="storageOptionalFields.includes(field)"
                                            class="ml-1 text-[10px] font-normal text-ink-subtle"
                                        >
                                            (optional)
                                        </span>
                                    </Label>
                                    <Input
                                        :id="`tenant_storage_${field}`"
                                        v-model="storageForm.credentials[field]"
                                        :type="isSecret(field) ? 'password' : 'text'"
                                        :placeholder="
                                            tenant.storage?.masked_credentials[field] as string ?? ''
                                        "
                                        class="h-9"
                                    />
                                    <InputError
                                        :message="
                                            (storageForm.errors as Record<string, string>)[
                                                `credentials.${field}`
                                            ]
                                        "
                                    />
                                </div>

                                <div class="flex flex-col gap-2">
                                    <button
                                        type="button"
                                        :disabled="
                                            !canTestStorage ||
                                            storageTestState.status === 'loading'
                                        "
                                        class="inline-flex self-start items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10 disabled:opacity-50"
                                        @click="testStorageConnection"
                                    >
                                        <Loader2
                                            v-if="storageTestState.status === 'loading'"
                                            class="size-3.5 animate-spin"
                                        />
                                        <Plug v-else class="size-3.5" />
                                        {{
                                            storageTestState.status === 'loading'
                                                ? t('system.cloud_providers.testing')
                                                : t('system.cloud_providers.test_connection')
                                        }}
                                    </button>
                                    <div
                                        v-if="storageTestState.status === 'success'"
                                        class="flex items-start gap-2 rounded-xs border border-sp-success/30 bg-sp-success/10 p-2 text-[11px] text-sp-success"
                                    >
                                        <CheckCircle2 class="mt-0.5 size-3.5 shrink-0" />
                                        <span>{{ storageTestState.message }}</span>
                                    </div>
                                    <div
                                        v-else-if="storageTestState.status === 'error'"
                                        class="flex items-start gap-2 rounded-xs border border-sp-danger/30 bg-sp-danger/10 p-2 text-[11px] text-sp-danger"
                                    >
                                        <XCircle class="mt-0.5 size-3.5 shrink-0" />
                                        <span>{{ storageTestState.message }}</span>
                                    </div>
                                </div>
                            </template>

                            <div class="flex justify-end pt-1">
                                <button
                                    type="submit"
                                    :disabled="storageForm.processing || !canSubmitStorage"
                                    class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                                >
                                    {{ t('system.cloud_providers.save') }}
                                </button>
                            </div>
                        </form>
                    </section>
                </TabsContent>

                <!-- =========================== DATABASE TAB =========================== -->
                <TabsContent value="database" class="mt-4 space-y-4">
                    <!-- Tenant override row. -->
                    <div
                        v-if="tenant.database"
                        class="flex items-start gap-3 rounded-sp-sm border border-soft bg-navy p-5"
                    >
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/15 text-accent-blue"
                        >
                            <Database class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-semibold text-ink">
                                    {{ tenant.database.display_name }}
                                </p>
                                <span
                                    class="inline-flex items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                                    style="color: var(--sp-success); border-color: color-mix(in oklab, var(--sp-success) 45%, transparent)"
                                >
                                    {{ t('system.cloud_providers.active') }}
                                </span>
                            </div>
                            <p
                                v-if="tenant.database.masked_credentials.host"
                                class="mt-0.5 truncate text-xs text-ink-muted"
                            >
                                {{ tenant.database.masked_credentials.host }}<span
                                    v-if="tenant.database.masked_credentials.port"
                                    >:{{ tenant.database.masked_credentials.port }}</span
                                >
                                <span v-if="tenant.database.masked_credentials.database">
                                    · {{ tenant.database.masked_credentials.database }}
                                </span>
                            </p>
                            <p class="mt-1 text-[11px] text-ink-subtle">
                                {{ t('system.cloud_providers.override_active_database') }}
                            </p>
                        </div>
                        <button
                            v-if="canManage"
                            type="button"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3 py-1 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20"
                            @click="removeOverride('database')"
                        >
                            <Trash2 class="size-3.5" />
                            {{ t('system.cloud_providers.remove_override') }}
                        </button>
                    </div>

                    <div
                        v-else-if="global.database"
                        class="flex items-start gap-2 rounded-sp-sm border border-accent-blue/30 bg-accent-blue/10 px-4 py-3 text-sm text-accent-blue"
                    >
                        <Info class="mt-0.5 size-4 shrink-0" />
                        <span>
                            {{
                                t('system.cloud_providers.using_global_database', {
                                    provider: global.database.display_name,
                                })
                            }}
                        </span>
                    </div>

                    <div
                        v-else
                        class="flex items-start gap-2 rounded-sp-sm border border-sp-danger/30 bg-sp-danger/10 px-4 py-3 text-sm text-sp-danger"
                    >
                        <Info class="mt-0.5 size-4 shrink-0" />
                        <span>{{ t('system.cloud_providers.using_global_database_unconfigured') }}</span>
                    </div>

                    <!-- Database config form. -->
                    <section
                        v-if="canManage"
                        class="rounded-sp-sm border border-soft bg-navy p-5"
                    >
                        <header class="mb-4 flex items-start gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-xs bg-accent-blue/15 text-accent-blue"
                            >
                                <Database class="size-4" />
                            </div>
                            <div>
                                <h3 class="text-sm font-medium text-ink">
                                    {{ t('system.cloud_providers.database_heading') }}
                                </h3>
                                <p class="text-xs text-ink-muted">
                                    {{ t('system.cloud_providers.database_description') }}
                                </p>
                            </div>
                        </header>

                        <form class="space-y-3" @submit.prevent="submitDatabase">
                            <div class="space-y-1.5">
                                <Label for="tenant_database_driver">
                                    {{ t('system.cloud_providers.provider') }}
                                </Label>
                                <Select v-model="databaseForm.driver">
                                    <SelectTrigger
                                        id="tenant_database_driver"
                                        class="h-9"
                                    >
                                        <SelectValue
                                            :placeholder="t('system.cloud_providers.select_provider')"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="driver in drivers.database"
                                            :key="driver.value"
                                            :value="driver.value"
                                        >
                                            {{ driver.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="databaseForm.errors.driver" />
                            </div>

                            <template v-if="databaseForm.driver">
                                <div
                                    v-for="field in databaseFields"
                                    :key="`tenant_database_${field}`"
                                    class="space-y-1.5"
                                >
                                    <Label :for="`tenant_database_${field}`">
                                        {{ fieldLabel(field) }}
                                        <span
                                            v-if="databaseOptionalFields.includes(field)"
                                            class="ml-1 text-[10px] font-normal text-ink-subtle"
                                        >
                                            (optional)
                                        </span>
                                    </Label>
                                    <Input
                                        :id="`tenant_database_${field}`"
                                        v-model="databaseForm.credentials[field]"
                                        :type="isSecret(field) ? 'password' : 'text'"
                                        :placeholder="
                                            tenant.database?.masked_credentials[field] as string ?? ''
                                        "
                                        class="h-9"
                                    />
                                    <InputError
                                        :message="
                                            (databaseForm.errors as Record<string, string>)[
                                                `credentials.${field}`
                                            ]
                                        "
                                    />
                                </div>

                                <div class="flex flex-col gap-2">
                                    <button
                                        type="button"
                                        :disabled="
                                            !canTestDatabase ||
                                            databaseTestState.status === 'loading'
                                        "
                                        class="inline-flex self-start items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10 disabled:opacity-50"
                                        @click="testDatabaseConnection"
                                    >
                                        <Loader2
                                            v-if="databaseTestState.status === 'loading'"
                                            class="size-3.5 animate-spin"
                                        />
                                        <Plug v-else class="size-3.5" />
                                        {{
                                            databaseTestState.status === 'loading'
                                                ? t('system.cloud_providers.testing')
                                                : t('system.cloud_providers.test_connection')
                                        }}
                                    </button>
                                    <div
                                        v-if="databaseTestState.status === 'success'"
                                        class="flex items-start gap-2 rounded-xs border border-sp-success/30 bg-sp-success/10 p-2 text-[11px] text-sp-success"
                                    >
                                        <CheckCircle2 class="mt-0.5 size-3.5 shrink-0" />
                                        <span>{{ databaseTestState.message }}</span>
                                    </div>
                                    <div
                                        v-else-if="databaseTestState.status === 'error'"
                                        class="flex items-start gap-2 rounded-xs border border-sp-danger/30 bg-sp-danger/10 p-2 text-[11px] text-sp-danger"
                                    >
                                        <XCircle class="mt-0.5 size-3.5 shrink-0" />
                                        <span>{{ databaseTestState.message }}</span>
                                    </div>
                                </div>
                            </template>

                            <div class="flex justify-end pt-1">
                                <button
                                    type="submit"
                                    :disabled="databaseForm.processing || !canSubmitDatabase"
                                    class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                                >
                                    {{ t('system.cloud_providers.save') }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <VectorStoreStatus
                        v-if="tenant.database"
                        i18n-namespace="system.cloud_providers"
                        :inspect-url="CloudProviderController.inspectVector().url"
                        :install-url="CloudProviderController.installVector().url"
                        :refresh-token="vectorRefreshToken"
                    />
                </TabsContent>
            </Tabs>
        </div>

        <WipeConfirmDialog
            v-model:open="wipeDialogOpen"
            :counts="wipeCounts"
            :is-global-scope="false"
            :processing="databaseForm.processing"
            @confirm="confirmWipeAndProceed"
            @cancel="
                wipeCounts = null;
                wipePendingAction = null;
            "
        />
    </AppLayoutV2>
</template>
