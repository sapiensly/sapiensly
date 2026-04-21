<script setup lang="ts">
import InviteUserDialog from '@/components/admin/InviteUserDialog.vue';
import UserDetailSheet from '@/components/admin/UserDetailSheet.vue';
import UserTable from '@/components/admin/UserTable.vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { AdminUser, UsersIndexProps } from '@/lib/admin/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Download, Plus, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<UsersIndexProps>();

const { t } = useI18n();

const q = ref(props.filters.q ?? '');
const role = ref(props.filters.role ?? 'any');
const status = ref(props.filters.status ?? 'any');

const activeUser = ref<AdminUser | null>(null);
const sheetOpen = ref(false);
const inviteOpen = ref(false);

const selectedIds = ref<Set<number>>(new Set());

function reload(overrides: Record<string, string | null> = {}) {
    const query: Record<string, string> = {};
    const current = {
        q: q.value,
        role: role.value === 'any' ? '' : role.value,
        status: status.value === 'any' ? '' : status.value,
        sort: props.filters.sort ?? '',
        ...overrides,
    };
    for (const [key, value] of Object.entries(current)) {
        if (value) query[key] = value as string;
    }

    router.get('/admin/users', query, {
        only: ['users', 'filters', 'summary'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const pushSearch = useDebounceFn(() => reload(), 250);

watch(q, () => pushSearch());
watch(role, () => reload());
watch(status, () => reload());

function setSort(key: NonNullable<UsersIndexProps['filters']['sort']>) {
    reload({ sort: key });
}

function toggle(id: number) {
    const next = new Set(selectedIds.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selectedIds.value = next;
}

function toggleAll() {
    const ids = props.users.data.map((u) => u.id);
    const allSelected = ids.every((id) => selectedIds.value.has(id));
    if (allSelected) {
        const next = new Set(selectedIds.value);
        ids.forEach((id) => next.delete(id));
        selectedIds.value = next;
    } else {
        const next = new Set(selectedIds.value);
        ids.forEach((id) => next.add(id));
        selectedIds.value = next;
    }
}

function openUser(user: AdminUser) {
    activeUser.value = user;
    sheetOpen.value = true;
}

function clearSelection() {
    selectedIds.value = new Set();
}

const selectionCount = computed(() => selectedIds.value.size);

const rangeLabel = computed(() =>
    t('admin.users.range_of', {
        shown: props.users.data.length,
        total: props.users.meta.total,
    }),
);
</script>

<template>
    <Head :title="t('admin.nav.users')" />

    <AdminLayout :title="t('admin.nav.users')">
        <div class="space-y-5">
            <!-- Header -->
            <header class="flex items-start justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="text-[22px] font-semibold leading-tight text-ink">
                        {{ t('admin.users.heading') }}
                    </h1>
                    <p class="text-xs text-ink-muted">
                        {{
                            t('admin.users.description_count', {
                                accounts: summary.accountsTotal.toLocaleString(),
                                orgs: summary.organizationsTotal.toLocaleString(),
                            })
                        }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                    >
                        <Download class="size-3.5" />
                        {{ t('admin.users.export_csv') }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        @click="inviteOpen = true"
                    >
                        <Plus class="size-3.5" />
                        {{ t('admin.users.invite.cta') }}
                    </button>
                </div>
            </header>

            <!-- Stacked filter rows, each full-width, per spec image. -->
            <div class="space-y-3">
                <div class="relative">
                    <Search
                        class="absolute top-1/2 left-4 size-3.5 -translate-y-1/2 text-ink-subtle"
                    />
                    <Input
                        v-model="q"
                        :placeholder="t('admin.users.filter.search')"
                        class="h-10 rounded-pill border-medium bg-white/5 pl-10 text-sm text-ink placeholder:text-ink-subtle"
                    />
                </div>

                <Select v-model="role">
                    <SelectTrigger
                        class="h-10 w-full border-medium bg-white/5 text-sm"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="any">
                            {{ t('admin.users.filter.role_any') }}
                        </SelectItem>
                        <SelectItem value="sysadmin">
                            {{ t('admin.users.role.sysadmin') }}
                        </SelectItem>
                        <SelectItem value="owner">
                            {{ t('admin.users.role.owner') }}
                        </SelectItem>
                        <SelectItem value="admin">
                            {{ t('admin.users.role.admin') }}
                        </SelectItem>
                        <SelectItem value="member">
                            {{ t('admin.users.role.member') }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select v-model="status">
                    <SelectTrigger
                        class="h-10 w-full border-medium bg-white/5 text-sm"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="any">
                            {{ t('admin.users.filter.status_any') }}
                        </SelectItem>
                        <SelectItem value="active">
                            {{ t('admin.users.status.active') }}
                        </SelectItem>
                        <SelectItem value="unverified">
                            {{ t('admin.users.status.unverified') }}
                        </SelectItem>
                        <SelectItem value="blocked">
                            {{ t('admin.users.status.blocked') }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- Range marker above the table. -->
            <p class="text-right text-xs text-ink-subtle">
                {{ rangeLabel }}
            </p>

            <!-- Selection bar (only visible with checkboxes picked). -->
            <div
                v-if="selectionCount > 0"
                class="flex items-center justify-between rounded-sp-sm border border-accent-blue/30 bg-accent-blue/5 px-4 py-2 text-sm"
            >
                <span class="text-ink-muted">
                    {{
                        t('admin.users.selection', { count: selectionCount })
                    }}
                </span>
                <button
                    type="button"
                    class="text-xs text-ink-muted hover:text-ink"
                    @click="clearSelection"
                >
                    {{ t('admin.users.clear_selection') }}
                </button>
            </div>

            <UserTable
                :users="users.data"
                :selected-ids="selectedIds"
                :sort="filters.sort ?? null"
                @toggle="toggle"
                @toggle-all="toggleAll"
                @sort="setSort"
                @open-user="openUser"
            />

            <!-- Pagination: "Showing X users" — Prev | Page n of m | Next -->
            <nav
                class="flex items-center justify-between pt-2 text-xs text-ink-muted"
            >
                <span>
                    {{
                        t('admin.users.showing_count', {
                            count: users.data.length,
                        })
                    }}
                </span>
                <div class="flex items-center gap-4">
                    <button
                        type="button"
                        class="text-ink-muted transition-colors hover:text-ink disabled:opacity-40 disabled:hover:text-ink-muted"
                        :disabled="users.meta.currentPage <= 1"
                        @click="
                            router.get(
                                '/admin/users',
                                { page: users.meta.currentPage - 1 },
                                { preserveState: true, preserveScroll: true },
                            )
                        "
                    >
                        {{ t('admin.users.pagination_prev') }}
                    </button>
                    <span class="text-ink-subtle">
                        {{
                            t('admin.users.page_of', {
                                current: users.meta.currentPage,
                                last: users.meta.lastPage,
                            })
                        }}
                    </span>
                    <button
                        type="button"
                        class="text-ink-muted transition-colors hover:text-ink disabled:opacity-40 disabled:hover:text-ink-muted"
                        :disabled="users.meta.currentPage >= users.meta.lastPage"
                        @click="
                            router.get(
                                '/admin/users',
                                { page: users.meta.currentPage + 1 },
                                { preserveState: true, preserveScroll: true },
                            )
                        "
                    >
                        {{ t('admin.users.pagination_next') }}
                    </button>
                </div>
            </nav>
        </div>

        <UserDetailSheet v-model:open="sheetOpen" :user="activeUser" />
        <InviteUserDialog v-model:open="inviteOpen" />
    </AdminLayout>
</template>
