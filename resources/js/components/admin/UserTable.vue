<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { AdminUser, UsersIndexProps } from '@/lib/admin/types';
import {
    AlertCircle,
    Check,
    ChevronDown,
    MoreVertical,
    Shield,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

type SortKey = NonNullable<UsersIndexProps['filters']['sort']>;

interface Props {
    users: AdminUser[];
    selectedIds: Set<number>;
    sort: SortKey | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'toggle', id: number): void;
    (e: 'toggle-all'): void;
    (e: 'sort', key: SortKey): void;
    (e: 'open-user', user: AdminUser): void;
}>();

const { t } = useI18n();

const allSelected = computed(
    () =>
        props.users.length > 0 &&
        props.users.every((u) => props.selectedIds.has(u.id)),
);
const someSelected = computed(
    () =>
        !allSelected.value &&
        props.users.some((u) => props.selectedIds.has(u.id)),
);

function nextSortFor(field: 'name' | 'lastSeen' | 'createdAt'): SortKey {
    if (props.sort === field) return `-${field}` as SortKey;
    if (props.sort === `-${field}`) return field;
    return field === 'name' ? 'name' : (`-${field}` as SortKey);
}

function sortDirection(field: 'name' | 'lastSeen' | 'createdAt'): 'asc' | 'desc' | null {
    if (props.sort === field) return 'asc';
    if (props.sort === `-${field}`) return 'desc';
    return null;
}

// ── Avatar tinting ─────────────────────────────────────────────────────
/** Six-colour rotation so initials don't all read as the same brand hue. */
const avatarPalette = [
    'var(--sp-spectrum-magenta)',
    'var(--sp-spectrum-cyan)',
    'var(--sp-spectrum-indigo)',
    'var(--sp-warning)',
    'var(--sp-success)',
    'var(--sp-accent-blue)',
];

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((n) => n[0]?.toUpperCase() ?? '')
            .join('') || '·'
    );
}

function avatarTint(name: string): string {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0;
    return avatarPalette[Math.abs(h) % avatarPalette.length];
}

// ── Role pill colour (outlined pills per image) ────────────────────────
function roleTint(role: AdminUser['role']): string {
    switch (role) {
        case 'owner':
            return 'var(--sp-spectrum-magenta)';
        case 'admin':
            return 'var(--sp-accent-cyan)';
        case 'sysadmin':
            return 'var(--sp-accent-blue)';
        case 'member':
        default:
            return 'var(--sp-spectrum-indigo)';
    }
}

// ── Status pill + icon ─────────────────────────────────────────────────
interface StatusStyle {
    icon: Component;
    tint: string;
}

const statusStyles: Record<AdminUser['status'], StatusStyle> = {
    active: { icon: Check, tint: 'var(--sp-success)' },
    unverified: { icon: AlertCircle, tint: 'var(--sp-warning)' },
    blocked: { icon: AlertCircle, tint: 'var(--sp-danger)' },
};

function relativeTime(iso: string | null): string {
    if (!iso) return t('admin.users.last_seen.never');
    const then = new Date(iso).getTime();
    const s = Math.round((Date.now() - then) / 1000);
    if (s < 60) return `${s}s ago`;
    const m = Math.round(s / 60);
    if (m < 60) return `${m}m ago`;
    const h = Math.round(m / 60);
    if (h < 24) return `${h}h ago`;
    return `${Math.round(h / 24)}d ago`;
}
</script>

<template>
    <div class="overflow-hidden rounded-sp-sm border border-soft bg-navy">
        <Table>
            <TableHeader class="bg-navy-deep/60">
                <TableRow class="border-soft hover:bg-transparent">
                    <TableHead class="w-10 pl-4">
                        <Checkbox
                            :model-value="
                                allSelected
                                    ? true
                                    : someSelected
                                      ? 'indeterminate'
                                      : false
                            "
                            @update:model-value="emit('toggle-all')"
                        />
                    </TableHead>
                    <TableHead
                        class="cursor-pointer text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                        @click="emit('sort', nextSortFor('name'))"
                    >
                        <span class="inline-flex items-center gap-1">
                            {{ t('admin.users.col.user') }}
                            <ChevronDown
                                v-if="sortDirection('name')"
                                class="size-3 transition-transform"
                                :class="
                                    sortDirection('name') === 'asc'
                                        ? 'rotate-180'
                                        : ''
                                "
                            />
                        </span>
                    </TableHead>
                    <TableHead
                        class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                    >
                        {{ t('admin.users.col.org') }}
                    </TableHead>
                    <TableHead
                        class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                    >
                        {{ t('admin.users.col.role') }}
                    </TableHead>
                    <TableHead
                        class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                    >
                        {{ t('admin.users.col.status') }}
                    </TableHead>
                    <TableHead
                        class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                    >
                        {{ t('admin.users.col.two_factor') }}
                    </TableHead>
                    <TableHead
                        class="cursor-pointer text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                        @click="emit('sort', nextSortFor('lastSeen'))"
                    >
                        <span class="inline-flex items-center gap-1">
                            {{ t('admin.users.col.last_seen') }}
                            <ChevronDown
                                v-if="sortDirection('lastSeen')"
                                class="size-3 transition-transform"
                                :class="
                                    sortDirection('lastSeen') === 'asc'
                                        ? 'rotate-180'
                                        : ''
                                "
                            />
                        </span>
                    </TableHead>
                    <TableHead class="w-10 pr-4" />
                </TableRow>
            </TableHeader>

            <TableBody>
                <TableEmpty v-if="users.length === 0" :colspan="8">
                    {{ t('admin.users.empty') }}
                </TableEmpty>

                <TableRow
                    v-for="user in users"
                    :key="user.id"
                    class="cursor-pointer border-soft transition-colors hover:bg-white/[0.03]"
                    @click="emit('open-user', user)"
                >
                    <TableCell class="pl-4" @click.stop>
                        <Checkbox
                            :model-value="selectedIds.has(user.id)"
                            @update:model-value="emit('toggle', user.id)"
                        />
                    </TableCell>

                    <!-- Avatar + name + email -->
                    <TableCell>
                        <div class="flex items-center gap-3">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-pill text-[11px] font-semibold text-white"
                                :style="{
                                    backgroundColor: avatarTint(user.name),
                                }"
                            >
                                {{ initials(user.name) }}
                            </span>
                            <div class="min-w-0 leading-tight">
                                <p class="truncate text-sm font-medium text-ink">
                                    {{ user.name }}
                                </p>
                                <p class="truncate text-xs text-ink-muted">
                                    {{ user.email }}
                                </p>
                            </div>
                        </div>
                    </TableCell>

                    <!-- Organization -->
                    <TableCell class="text-sm text-ink">
                        {{ user.org?.name ?? '—' }}
                    </TableCell>

                    <!-- Role pill — outlined with role tint -->
                    <TableCell>
                        <span
                            class="inline-flex items-center rounded-pill border px-2.5 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                            :style="{
                                color: roleTint(user.role),
                                borderColor: `color-mix(in oklab, ${roleTint(user.role)} 50%, transparent)`,
                            }"
                        >
                            {{ t(`admin.users.role.${user.role}`) }}
                        </span>
                    </TableCell>

                    <!-- Status pill with icon -->
                    <TableCell>
                        <span
                            class="inline-flex items-center gap-1 rounded-pill border px-2.5 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                            :style="{
                                color: statusStyles[user.status].tint,
                                borderColor: `color-mix(in oklab, ${statusStyles[user.status].tint} 45%, transparent)`,
                                backgroundColor: `color-mix(in oklab, ${statusStyles[user.status].tint} 10%, transparent)`,
                            }"
                        >
                            <component
                                :is="statusStyles[user.status].icon"
                                class="size-3"
                            />
                            {{ t(`admin.users.status.${user.status}`) }}
                        </span>
                    </TableCell>

                    <!-- 2FA -->
                    <TableCell>
                        <span
                            v-if="user.twoFactorEnabled"
                            class="inline-flex items-center gap-1 text-xs text-sp-success"
                        >
                            <Shield class="size-3" />
                            {{ t('admin.users.two_factor.on') }}
                        </span>
                        <span v-else class="text-xs text-ink-subtle">
                            {{ t('admin.users.two_factor.off') }}
                        </span>
                    </TableCell>

                    <!-- Last seen -->
                    <TableCell class="text-xs text-ink-muted">
                        {{ relativeTime(user.lastSeenAt) }}
                    </TableCell>

                    <!-- Kebab -->
                    <TableCell class="pr-4" @click.stop>
                        <button
                            type="button"
                            class="flex size-7 items-center justify-center rounded-xs text-ink-subtle transition-colors hover:bg-white/5 hover:text-ink"
                            @click="emit('open-user', user)"
                        >
                            <MoreVertical class="size-4" />
                        </button>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
