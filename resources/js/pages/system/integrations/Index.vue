<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import IntegrationCard from '@/components/integrations/IntegrationCard.vue';
import IntegrationEmptyState from '@/components/integrations/IntegrationEmptyState.vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { LayoutTemplate, Plus, Search } from 'lucide-vue-next';
import { ref, watch } from 'vue';
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

    <AppLayoutV2 :title="t('app_v2.nav.integrations')">
        <div class="space-y-5">
            <PageHeader
                :title="t('app_v2.integrations.heading')"
                :description="t('app_v2.integrations.description')"
            >
                <template #actions>
                    <Link href="/system/integrations/templates">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        >
                            <LayoutTemplate class="size-3.5" />
                            {{ t('system.integrations.templates.cta') }}
                        </button>
                    </Link>
                    <Link href="/system/integrations/create">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Plus class="size-3.5" />
                            {{ t('system.integrations.new') }}
                        </button>
                    </Link>
                </template>
            </PageHeader>

            <div
                v-if="integrations.length > 0"
                class="flex flex-wrap items-center gap-3"
            >
                <div class="relative flex-1 min-w-[220px]">
                    <Search
                        class="absolute top-1/2 left-4 size-3.5 -translate-y-1/2 text-ink-subtle"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        :placeholder="t('system.integrations.search_placeholder')"
                        class="h-10 rounded-pill border-medium bg-white/5 pl-10 text-sm text-ink placeholder:text-ink-subtle"
                    />
                </div>
                <Select v-model="authTypeFilter">
                    <SelectTrigger
                        class="h-10 w-56 border-medium bg-white/5 text-sm"
                    >
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
    </AppLayoutV2>
</template>
