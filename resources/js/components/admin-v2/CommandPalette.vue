<script setup lang="ts">
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    NavAccess,
    NavAi,
    NavCloud,
    NavDashboard,
    NavStack,
    NavUsers,
} from '@/lib/admin/icons';
import { router } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

export interface PaletteCommand {
    id: string;
    group: string; // translated group label
    label: string;
    icon?: Component;
    perform: () => void;
    keywords?: string[];
}

const { t } = useI18n();
const open = ref(false);

// External pages can push actions in via a global registry (keeps the
// palette owning its own state without forcing every page to wire a prop).
const pageCommands = ref<PaletteCommand[]>([]);

function registerCommand(cmd: PaletteCommand) {
    pageCommands.value = [
        ...pageCommands.value.filter((c) => c.id !== cmd.id),
        cmd,
    ];
    return () => {
        pageCommands.value = pageCommands.value.filter(
            (c) => c.id !== cmd.id,
        );
    };
}

// Exposed via defineExpose so parents (AdminV2Layout) can wire them if
// they need programmatic control (e.g., a Topbar button that opens the
// palette).
defineExpose({ open, registerCommand });

const navCommands = computed<PaletteCommand[]>(() => [
    {
        id: 'nav-dashboard',
        group: t('admin_v2.palette.navigation'),
        label: t('admin_v2.nav.dashboard'),
        icon: NavDashboard,
        perform: () => router.visit('/admin2'),
    },
    {
        id: 'nav-users',
        group: t('admin_v2.palette.navigation'),
        label: t('admin_v2.nav.users'),
        icon: NavUsers,
        perform: () => router.visit('/admin2/users'),
    },
    {
        id: 'nav-access',
        group: t('admin_v2.palette.navigation'),
        label: t('admin_v2.nav.access'),
        icon: NavAccess,
        perform: () => router.visit('/admin2/access'),
    },
    {
        id: 'nav-ai',
        group: t('admin_v2.palette.navigation'),
        label: t('admin_v2.nav.ai'),
        icon: NavAi,
        perform: () => router.visit('/admin2/ai'),
    },
    {
        id: 'nav-cloud',
        group: t('admin_v2.palette.navigation'),
        label: t('admin_v2.nav.cloud'),
        icon: NavCloud,
        perform: () => router.visit('/admin2/cloud'),
    },
    {
        id: 'nav-stack',
        group: t('admin_v2.palette.navigation'),
        label: t('admin_v2.nav.stack'),
        icon: NavStack,
        perform: () => router.visit('/admin2/stack'),
    },
]);

const groupedCommands = computed(() => {
    const all = [...navCommands.value, ...pageCommands.value];
    const groups = new Map<string, PaletteCommand[]>();
    for (const cmd of all) {
        if (!groups.has(cmd.group)) groups.set(cmd.group, []);
        groups.get(cmd.group)!.push(cmd);
    }
    return Array.from(groups.entries()).map(([group, items]) => ({
        group,
        items,
    }));
});

function run(cmd: PaletteCommand) {
    open.value = false;
    cmd.perform();
}

function onKeydown(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        open.value = !open.value;
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <CommandDialog
        v-model:open="open"
        content-class="sp-admin-menu sp-admin-palette rounded-sp-sm"
    >
        <CommandInput :placeholder="t('admin_v2.palette.search_placeholder')" />
        <CommandList>
            <CommandEmpty>{{ t('admin_v2.palette.empty') }}</CommandEmpty>
            <template
                v-for="(group, idx) in groupedCommands"
                :key="group.group"
            >
                <CommandSeparator v-if="idx > 0" />
                <CommandGroup :heading="group.group">
                    <CommandItem
                        v-for="cmd in group.items"
                        :key="cmd.id"
                        :value="`${cmd.group}|${cmd.label}|${(cmd.keywords ?? []).join(' ')}`"
                        @select="run(cmd)"
                    >
                        <component
                            :is="cmd.icon"
                            v-if="cmd.icon"
                            class="mr-2 size-4 text-ink-muted"
                        />
                        {{ cmd.label }}
                    </CommandItem>
                </CommandGroup>
            </template>
        </CommandList>
    </CommandDialog>
</template>
