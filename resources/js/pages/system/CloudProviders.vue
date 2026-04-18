<script setup lang="ts">
import * as CloudProviderController from '@/actions/App/Http/Controllers/CloudProviderController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import VectorStoreStatus from '@/components/VectorStoreStatus.vue';
import WipeConfirmDialog, { type WipeCounts } from '@/components/WipeConfirmDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
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

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('nav.system'), href: '#' },
    {
        title: t('system.cloud_providers.title'),
        href: CloudProviderController.index().url,
    },
]);

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

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <Heading
                    :title="t('system.cloud_providers.title')"
                    :description="t('system.cloud_providers.description')"
                />

                <div
                    v-if="!canManage"
                    class="mt-4 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
                >
                    <Info class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ t('system.cloud_providers.read_only_notice') }}</span>
                </div>

                <Tabs v-model="activeTab" class="mt-6">
                    <TabsList class="grid w-full max-w-md grid-cols-2">
                        <TabsTrigger value="storage">
                            {{ t('system.cloud_providers.tab_storage') }}
                        </TabsTrigger>
                        <TabsTrigger value="database">
                            {{ t('system.cloud_providers.tab_database') }}
                        </TabsTrigger>
                    </TabsList>

                    <!-- =========================== STORAGE TAB =========================== -->
                    <TabsContent value="storage" class="mt-6">
                        <!-- Tenant override card -->
                        <Card v-if="tenant.storage" class="mb-4">
                            <CardContent class="pt-6">
                                <div class="flex items-start gap-3">
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                                    >
                                        <HardDrive class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-medium">
                                                {{ tenant.storage.display_name }}
                                            </p>
                                            <Badge variant="default" class="text-xs">
                                                {{ t('system.cloud_providers.active') }}
                                            </Badge>
                                        </div>
                                        <p
                                            v-if="tenant.storage.masked_credentials.bucket"
                                            class="mt-1 truncate text-xs text-muted-foreground"
                                        >
                                            {{ tenant.storage.masked_credentials.bucket }}
                                            <span
                                                v-if="tenant.storage.masked_credentials.region"
                                            >
                                                · {{ tenant.storage.masked_credentials.region }}
                                            </span>
                                        </p>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            {{ t('system.cloud_providers.override_active_storage') }}
                                        </p>
                                    </div>
                                    <Button
                                        v-if="canManage"
                                        variant="ghost"
                                        size="sm"
                                        @click="removeOverride('storage')"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4 text-destructive" />
                                        {{ t('system.cloud_providers.remove_override') }}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Global fallback banner -->
                        <div
                            v-else-if="global.storage"
                            class="mb-4 flex items-start gap-2 rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300"
                        >
                            <Info class="mt-0.5 h-4 w-4 shrink-0" />
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
                            class="mb-4 flex items-start gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
                        >
                            <Info class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ t('system.cloud_providers.using_global_storage_unconfigured') }}</span>
                        </div>

                        <section v-if="canManage" class="rounded-lg border p-5">
                            <div class="mb-4 flex items-start gap-3">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                                >
                                    <Cloud class="h-5 w-5" />
                                </div>
                                <div>
                                    <h3 class="text-base font-semibold">
                                        {{ t('system.cloud_providers.storage_heading') }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ t('system.cloud_providers.storage_description') }}
                                    </p>
                                </div>
                            </div>

                            <form class="space-y-4" @submit.prevent="submitStorage">
                                <div class="grid gap-2">
                                    <Label for="tenant_storage_driver">
                                        {{ t('system.cloud_providers.provider') }}
                                    </Label>
                                    <Select v-model="storageForm.driver">
                                        <SelectTrigger id="tenant_storage_driver">
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
                                        class="grid gap-2"
                                    >
                                        <Label :for="`tenant_storage_${field}`">
                                            {{ fieldLabel(field) }}
                                            <span
                                                v-if="storageOptionalFields.includes(field)"
                                                class="ml-1 text-xs font-normal text-muted-foreground"
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
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="self-start"
                                            :disabled="
                                                !canTestStorage ||
                                                storageTestState.status === 'loading'
                                            "
                                            @click="testStorageConnection"
                                        >
                                            <Loader2
                                                v-if="storageTestState.status === 'loading'"
                                                class="mr-2 h-4 w-4 animate-spin"
                                            />
                                            <Plug v-else class="mr-2 h-4 w-4" />
                                            {{
                                                storageTestState.status === 'loading'
                                                    ? t('system.cloud_providers.testing')
                                                    : t('system.cloud_providers.test_connection')
                                            }}
                                        </Button>
                                        <div
                                            v-if="storageTestState.status === 'success'"
                                            class="flex items-start gap-2 rounded-md border border-green-200 bg-green-50 p-2 text-xs text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300"
                                        >
                                            <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
                                            <span>{{ storageTestState.message }}</span>
                                        </div>
                                        <div
                                            v-else-if="storageTestState.status === 'error'"
                                            class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
                                        >
                                            <XCircle class="mt-0.5 h-4 w-4 shrink-0" />
                                            <span>{{ storageTestState.message }}</span>
                                        </div>
                                    </div>
                                </template>

                                <div class="flex justify-end">
                                    <Button
                                        type="submit"
                                        :disabled="storageForm.processing || !canSubmitStorage"
                                    >
                                        {{ t('system.cloud_providers.save') }}
                                    </Button>
                                </div>
                            </form>
                        </section>
                    </TabsContent>

                    <!-- =========================== DATABASE TAB =========================== -->
                    <TabsContent value="database" class="mt-6">
                        <Card v-if="tenant.database" class="mb-4">
                            <CardContent class="pt-6">
                                <div class="flex items-start gap-3">
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                                    >
                                        <Database class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-medium">
                                                {{ tenant.database.display_name }}
                                            </p>
                                            <Badge variant="default" class="text-xs">
                                                {{ t('system.cloud_providers.active') }}
                                            </Badge>
                                        </div>
                                        <p
                                            v-if="tenant.database.masked_credentials.host"
                                            class="mt-1 truncate text-xs text-muted-foreground"
                                        >
                                            {{ tenant.database.masked_credentials.host }}<span
                                                v-if="tenant.database.masked_credentials.port"
                                                >:{{ tenant.database.masked_credentials.port }}</span
                                            >
                                            <span
                                                v-if="tenant.database.masked_credentials.database"
                                            >
                                                · {{ tenant.database.masked_credentials.database }}
                                            </span>
                                        </p>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            {{ t('system.cloud_providers.override_active_database') }}
                                        </p>
                                    </div>
                                    <Button
                                        v-if="canManage"
                                        variant="ghost"
                                        size="sm"
                                        @click="removeOverride('database')"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4 text-destructive" />
                                        {{ t('system.cloud_providers.remove_override') }}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>

                        <div
                            v-else-if="global.database"
                            class="mb-4 flex items-start gap-2 rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300"
                        >
                            <Info class="mt-0.5 h-4 w-4 shrink-0" />
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
                            class="mb-4 flex items-start gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
                        >
                            <Info class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ t('system.cloud_providers.using_global_database_unconfigured') }}</span>
                        </div>

                        <section v-if="canManage" class="rounded-lg border p-5">
                            <div class="mb-4 flex items-start gap-3">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                                >
                                    <Database class="h-5 w-5" />
                                </div>
                                <div>
                                    <h3 class="text-base font-semibold">
                                        {{ t('system.cloud_providers.database_heading') }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ t('system.cloud_providers.database_description') }}
                                    </p>
                                </div>
                            </div>

                            <form class="space-y-4" @submit.prevent="submitDatabase">
                                <div class="grid gap-2">
                                    <Label for="tenant_database_driver">
                                        {{ t('system.cloud_providers.provider') }}
                                    </Label>
                                    <Select v-model="databaseForm.driver">
                                        <SelectTrigger id="tenant_database_driver">
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
                                        class="grid gap-2"
                                    >
                                        <Label :for="`tenant_database_${field}`">
                                            {{ fieldLabel(field) }}
                                            <span
                                                v-if="databaseOptionalFields.includes(field)"
                                                class="ml-1 text-xs font-normal text-muted-foreground"
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
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="self-start"
                                            :disabled="
                                                !canTestDatabase ||
                                                databaseTestState.status === 'loading'
                                            "
                                            @click="testDatabaseConnection"
                                        >
                                            <Loader2
                                                v-if="databaseTestState.status === 'loading'"
                                                class="mr-2 h-4 w-4 animate-spin"
                                            />
                                            <Plug v-else class="mr-2 h-4 w-4" />
                                            {{
                                                databaseTestState.status === 'loading'
                                                    ? t('system.cloud_providers.testing')
                                                    : t('system.cloud_providers.test_connection')
                                            }}
                                        </Button>
                                        <div
                                            v-if="databaseTestState.status === 'success'"
                                            class="flex items-start gap-2 rounded-md border border-green-200 bg-green-50 p-2 text-xs text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300"
                                        >
                                            <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
                                            <span>{{ databaseTestState.message }}</span>
                                        </div>
                                        <div
                                            v-else-if="databaseTestState.status === 'error'"
                                            class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
                                        >
                                            <XCircle class="mt-0.5 h-4 w-4 shrink-0" />
                                            <span>{{ databaseTestState.message }}</span>
                                        </div>
                                    </div>
                                </template>

                                <div class="flex justify-end">
                                    <Button
                                        type="submit"
                                        :disabled="databaseForm.processing || !canSubmitDatabase"
                                    >
                                        {{ t('system.cloud_providers.save') }}
                                    </Button>
                                </div>
                            </form>
                        </section>

                        <VectorStoreStatus
                            v-if="tenant.database"
                            class="mt-6"
                            i18n-namespace="system.cloud_providers"
                            :inspect-url="CloudProviderController.inspectVector().url"
                            :install-url="CloudProviderController.installVector().url"
                            :refresh-token="vectorRefreshToken"
                        />
                    </TabsContent>
                </Tabs>
            </div>
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
    </AppLayout>
</template>
