<script setup lang="ts">
import AdminDashboardController from '@/actions/App/Http/Controllers/Admin/AdminDashboardController';
import * as GlobalCloudController from '@/actions/App/Http/Controllers/Admin/GlobalCloudController';
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
import AdminLayout from '@/layouts/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
    CheckCircle2,
    Cloud,
    Database,
    HardDrive,
    Loader2,
    Plug,
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

interface ExistingProvider {
    id: string;
    driver: string;
    display_name: string;
    masked_credentials: Record<string, string | null>;
    status: string;
}

interface Props {
    drivers: {
        storage: DriverOption[];
        database: DriverOption[];
    };
    existing: {
        storage: ExistingProvider | null;
        database: ExistingProvider | null;
    };
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Admin', href: AdminDashboardController().url },
    { title: t('admin.global_cloud.title'), href: GlobalCloudController.index().url },
]);

const activeTab = ref<'storage' | 'database'>('storage');

const fieldLabel = (field: string): string =>
    t(`admin.global_cloud.fields.${field}`, field);

const isSecret = (field: string): boolean =>
    ['secret', 'password', 'key'].includes(field);

// ============================================================================
// Storage form
// ============================================================================
const emptyStorageCredentials = (): Record<string, string> => ({});

const storageForm = useForm({
    driver: props.existing.storage?.driver ?? '',
    credentials: emptyStorageCredentials(),
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

const canSubmitStorage = computed<boolean>(() => {
    if (!storageForm.driver || !selectedStorageDriver.value) return false;
    return storageFields.value.every((field) => {
        if (storageOptionalFields.value.includes(field)) return true;
        return !!storageForm.credentials[field];
    });
});

watch(
    () => storageForm.driver,
    (driver, previous) => {
        if (driver === previous) return;
        storageForm.credentials = emptyStorageCredentials();
    },
);

const submitStorage = () => {
    storageForm.post(GlobalCloudController.storeStorage().url, {
        preserveScroll: true,
        onSuccess: () => {
            storageForm.credentials = emptyStorageCredentials();
        },
    });
};

// ============================================================================
// Database form
// ============================================================================
const emptyDatabaseCredentials = (): Record<string, string> => ({});

const databaseForm = useForm({
    driver: props.existing.database?.driver ?? 'postgresql',
    credentials: emptyDatabaseCredentials(),
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

const canSubmitDatabase = computed<boolean>(() => {
    if (!databaseForm.driver || !selectedDatabaseDriver.value) return false;
    return databaseFields.value.every((field) => {
        if (databaseOptionalFields.value.includes(field)) return true;
        return !!databaseForm.credentials[field];
    });
});

watch(
    () => databaseForm.driver,
    (driver, previous) => {
        if (driver === previous) return;
        databaseForm.credentials = emptyDatabaseCredentials();
    },
);

const vectorRefreshToken = ref(0);

const wipeDialogOpen = ref(false);
const wipeCounts = ref<WipeCounts | null>(null);

const postDatabase = (withConfirm: boolean) => {
    databaseForm
        .transform((data) => (withConfirm ? { ...data, confirm: 'DELETE' } : data))
        .post(GlobalCloudController.storeDatabase().url, {
            preserveScroll: true,
            onSuccess: () => {
                const wipe = (usePage().props.flash as { wipe_required?: WipeCounts } | undefined)
                    ?.wipe_required;
                if (wipe) {
                    wipeCounts.value = wipe;
                    wipeDialogOpen.value = true;
                    return;
                }

                wipeDialogOpen.value = false;
                wipeCounts.value = null;
                databaseForm.credentials = emptyDatabaseCredentials();
                vectorRefreshToken.value++;
            },
        });
};

const submitDatabase = () => {
    postDatabase(false);
};

const confirmWipeAndSave = () => {
    postDatabase(true);
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

const isPayloadComplete = (fields: string[], optional: string[], values: Record<string, string>): boolean =>
    fields.every((field) => optional.includes(field) || !!values[field]);

const canTestStorage = computed<boolean>(() => {
    if (
        !!storageForm.driver &&
        isPayloadComplete(storageFields.value, storageOptionalFields.value, storageForm.credentials)
    ) {
        return true;
    }
    return !!props.existing.storage;
});

const canTestDatabase = computed<boolean>(() => {
    if (
        !!databaseForm.driver &&
        isPayloadComplete(databaseFields.value, databaseOptionalFields.value, databaseForm.credentials)
    ) {
        return true;
    }
    return !!props.existing.database;
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
            ? {
                  status: 'success',
                  message: data.message || t('admin.global_cloud.test_success'),
              }
            : {
                  status: 'error',
                  message:
                      data.detail ||
                      data.message ||
                      t('admin.global_cloud.test_failed'),
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
                t('admin.global_cloud.test_failed'),
        };
    }
}

const testStorageConnection = () => {
    if (
        storageForm.driver &&
        isPayloadComplete(storageFields.value, storageOptionalFields.value, storageForm.credentials)
    ) {
        runTest(storageTestState, GlobalCloudController.testStorage().url, {
            driver: storageForm.driver,
            credentials: storageForm.credentials,
        });
        return;
    }
    runTest(storageTestState, GlobalCloudController.testStorage().url, { use_saved: true });
};

const testDatabaseConnection = () => {
    if (
        databaseForm.driver &&
        isPayloadComplete(databaseFields.value, databaseOptionalFields.value, databaseForm.credentials)
    ) {
        runTest(databaseTestState, GlobalCloudController.testDatabase().url, {
            driver: databaseForm.driver,
            credentials: databaseForm.credentials,
        });
        return;
    }
    runTest(databaseTestState, GlobalCloudController.testDatabase().url, { use_saved: true });
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
    <Head :title="t('admin.global_cloud.title')" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <Heading
                    :title="t('admin.global_cloud.title')"
                    :description="t('admin.global_cloud.description')"
                />

                <Tabs v-model="activeTab" class="mt-6">
                    <TabsList class="grid w-full max-w-md grid-cols-2">
                        <TabsTrigger value="storage">
                            {{ t('admin.global_cloud.tab_storage') }}
                        </TabsTrigger>
                        <TabsTrigger value="database">
                            {{ t('admin.global_cloud.tab_database') }}
                        </TabsTrigger>
                    </TabsList>

                    <!-- =========================== STORAGE TAB =========================== -->
                    <TabsContent value="storage" class="mt-6">
                        <Card v-if="existing.storage" class="mb-6">
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
                                                {{ existing.storage.display_name }}
                                            </p>
                                            <Badge variant="default" class="text-xs">
                                                {{ t('admin.global_cloud.active') }}
                                            </Badge>
                                        </div>
                                        <p
                                            v-if="existing.storage.masked_credentials.bucket"
                                            class="mt-1 truncate text-xs text-muted-foreground"
                                        >
                                            {{ existing.storage.masked_credentials.bucket }}
                                            <span
                                                v-if="existing.storage.masked_credentials.region"
                                            >
                                                · {{ existing.storage.masked_credentials.region }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <section class="rounded-lg border p-5">
                            <div class="mb-4 flex items-start gap-3">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                                >
                                    <Cloud class="h-5 w-5" />
                                </div>
                                <div>
                                    <h3 class="text-base font-semibold">
                                        {{ t('admin.global_cloud.storage_heading') }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ t('admin.global_cloud.storage_description') }}
                                    </p>
                                </div>
                            </div>

                            <form class="space-y-4" @submit.prevent="submitStorage">
                                <div class="grid gap-2">
                                    <Label for="storage_driver">
                                        {{ t('admin.global_cloud.provider') }}
                                    </Label>
                                    <Select v-model="storageForm.driver">
                                        <SelectTrigger id="storage_driver">
                                            <SelectValue
                                                :placeholder="t('admin.global_cloud.select_provider')"
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
                                        :key="`storage_${field}`"
                                        class="grid gap-2"
                                    >
                                        <Label :for="`storage_${field}`">
                                            {{ fieldLabel(field) }}
                                            <span
                                                v-if="storageOptionalFields.includes(field)"
                                                class="ml-1 text-xs font-normal text-muted-foreground"
                                            >
                                                (optional)
                                            </span>
                                        </Label>
                                        <Input
                                            :id="`storage_${field}`"
                                            v-model="storageForm.credentials[field]"
                                            :type="isSecret(field) ? 'password' : 'text'"
                                            :placeholder="
                                                existing.storage?.masked_credentials[field] as string ?? ''
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
                                                    ? t('admin.global_cloud.testing')
                                                    : t('admin.global_cloud.test_connection')
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
                                        {{ t('admin.global_cloud.save') }}
                                    </Button>
                                </div>
                            </form>
                        </section>
                    </TabsContent>

                    <!-- =========================== DATABASE TAB =========================== -->
                    <TabsContent value="database" class="mt-6">
                        <Card v-if="existing.database" class="mb-6">
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
                                                {{ existing.database.display_name }}
                                            </p>
                                            <Badge variant="default" class="text-xs">
                                                {{ t('admin.global_cloud.active') }}
                                            </Badge>
                                        </div>
                                        <p
                                            v-if="existing.database.masked_credentials.host"
                                            class="mt-1 truncate text-xs text-muted-foreground"
                                        >
                                            {{ existing.database.masked_credentials.host }}<span
                                                v-if="existing.database.masked_credentials.port"
                                                >:{{ existing.database.masked_credentials.port }}</span
                                            >
                                            <span
                                                v-if="existing.database.masked_credentials.database"
                                            >
                                                · {{ existing.database.masked_credentials.database }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <section class="rounded-lg border p-5">
                            <div class="mb-4 flex items-start gap-3">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                                >
                                    <Database class="h-5 w-5" />
                                </div>
                                <div>
                                    <h3 class="text-base font-semibold">
                                        {{ t('admin.global_cloud.database_heading') }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ t('admin.global_cloud.database_description') }}
                                    </p>
                                </div>
                            </div>

                            <form class="space-y-4" @submit.prevent="submitDatabase">
                                <div class="grid gap-2">
                                    <Label for="database_driver">
                                        {{ t('admin.global_cloud.provider') }}
                                    </Label>
                                    <Select v-model="databaseForm.driver">
                                        <SelectTrigger id="database_driver">
                                            <SelectValue
                                                :placeholder="t('admin.global_cloud.select_provider')"
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
                                        :key="`database_${field}`"
                                        class="grid gap-2"
                                    >
                                        <Label :for="`database_${field}`">
                                            {{ fieldLabel(field) }}
                                            <span
                                                v-if="databaseOptionalFields.includes(field)"
                                                class="ml-1 text-xs font-normal text-muted-foreground"
                                            >
                                                (optional)
                                            </span>
                                        </Label>
                                        <Input
                                            :id="`database_${field}`"
                                            v-model="databaseForm.credentials[field]"
                                            :type="isSecret(field) ? 'password' : 'text'"
                                            :placeholder="
                                                existing.database?.masked_credentials[field] as string ?? ''
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
                                                    ? t('admin.global_cloud.testing')
                                                    : t('admin.global_cloud.test_connection')
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
                                        {{ t('admin.global_cloud.save') }}
                                    </Button>
                                </div>
                            </form>
                        </section>

                        <VectorStoreStatus
                            v-if="existing.database"
                            class="mt-6"
                            i18n-namespace="admin.global_cloud"
                            :inspect-url="GlobalCloudController.inspectVector().url"
                            :install-url="GlobalCloudController.installVector().url"
                            :refresh-token="vectorRefreshToken"
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </div>

        <WipeConfirmDialog
            v-model:open="wipeDialogOpen"
            :counts="wipeCounts"
            :is-global-scope="true"
            :processing="databaseForm.processing"
            @confirm="confirmWipeAndSave"
            @cancel="wipeCounts = null"
        />
    </AdminLayout>
</template>
