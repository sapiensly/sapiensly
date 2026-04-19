<script setup lang="ts">
import IntegrationCard from '@/components/integrations/IntegrationCard.vue';
import IntegrationEmptyState from '@/components/integrations/IntegrationEmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface IntegrationSummary {
    id: string;
    name: string;
    slug: string;
    base_url: string;
    auth_type: string;
    visibility: string;
    status: string;
    last_tested_at: string | null;
    last_test_status: string | null;
    request_count: number;
}

interface Props {
    integrations: IntegrationSummary[];
    filters: { search: string | null; auth_type: string | null };
    authTypes: Array<{ value: string; label: string }>;
}

const props = defineProps<Props>();

const { t } = useI18n();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('nav.system'), href: '#' },
    { title: t('system.integrations.title'), href: '/system/integrations' },
]);

const search = ref<string>(props.filters.search ?? '');
const authTypeFilter = ref<string>(props.filters.auth_type ?? 'all');

let searchTimer: number | undefined;
watch(search, (value) => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        applyFilters(value, authTypeFilter.value);
    }, 300);
});
watch(authTypeFilter, (value) => applyFilters(search.value, value));

function applyFilters(searchValue: string, authType: string): void {
    router.get(
        '/system/integrations',
        {
            search: searchValue || undefined,
            auth_type: authType === 'all' ? undefined : authType,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function handleDuplicate(id: string): void {
    router.post(`/system/integrations/${id}/duplicate`);
}

function handleDelete(id: string): void {
    if (!confirm(t('system.integrations.delete_confirm'))) return;
    router.delete(`/system/integrations/${id}`);
}
</script>

<template>
    <Head :title="t('system.integrations.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-5xl">
                <div class="mb-6 flex items-start justify-between gap-4">
                    <Heading
                        :title="t('system.integrations.title')"
                        :description="t('system.integrations.description')"
                    />
                    <Button as-child>
                        <Link href="/system/integrations/create">
                            <Plus class="mr-2 h-4 w-4" />
                            {{ t('system.integrations.new') }}
                        </Link>
                    </Button>
                </div>

                <div
                    v-if="integrations.length > 0"
                    class="mb-4 flex flex-wrap items-center gap-2"
                >
                    <div class="relative flex-1 min-w-[220px]">
                        <Search
                            class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="search"
                            type="search"
                            :placeholder="t('system.integrations.search_placeholder')"
                            class="pl-9"
                        />
                    </div>
                    <Select v-model="authTypeFilter">
                        <SelectTrigger class="w-56">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                {{ t('system.integrations.filter_all_auth') }}
                            </SelectItem>
                            <SelectItem
                                v-for="option in authTypes"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <IntegrationEmptyState v-if="integrations.length === 0" />

                <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <IntegrationCard
                        v-for="integration in integrations"
                        :key="integration.id"
                        :integration="integration"
                        @duplicate="handleDuplicate"
                        @delete="handleDelete"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
