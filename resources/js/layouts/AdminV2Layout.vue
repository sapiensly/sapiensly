<script setup lang="ts">
import '@/../css/admin.css';

import CommandPalette from '@/components/admin-v2/CommandPalette.vue';
import Sidebar from '@/components/admin-v2/Sidebar.vue';
import Topbar from '@/components/admin-v2/Topbar.vue';
import { useLocaleSync } from '@/composables/useLocale';
import type { AppPageProps } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

useLocaleSync();

interface Props {
    title: string;
    /** Background treatment — defaults to 'blueprint' per layout_spec §2. */
    bg?: 'blueprint' | 'flat' | 'spectrum' | 'subtle';
}

withDefaults(defineProps<Props>(), { bg: 'blueprint' });

// The legacy /admin layout (AppShell + SidebarProvider) reads this same
// `sidebar_state` cookie and exposes it as `sidebarOpen` on every Inertia
// page. We share the cookie name so collapsing in one admin persists in
// the other — and so the next page load remembers the state.
const page = usePage<AppPageProps>();
const initialOpen = (page.props.sidebarOpen ?? true) as boolean;
const sidebarCollapsed = ref(!initialOpen);

const paletteRef = ref<InstanceType<typeof CommandPalette> | null>(null);

function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    const open = !sidebarCollapsed.value;
    // 7-day rolling cookie, path-wide so the legacy admin sees it too.
    document.cookie = `sidebar_state=${open}; path=/; max-age=${60 * 60 * 24 * 7}`;
}

function openPalette() {
    if (paletteRef.value) paletteRef.value.open = true;
}
</script>

<template>
    <div class="sp-admin-shell flex min-h-screen" :data-bg="bg">
        <!--
          Sidebar sticks to its own column and spans the full viewport height.
          Collapsing to a 64px icon rail is triggered from the Topbar via the
          toggle button (same cookie + behaviour as the legacy /admin shell).
        -->
        <Sidebar :collapsed="sidebarCollapsed" class="hidden lg:flex" />

        <!--
          Content column owns the grid overlay so the 50px lines stop at the
          sidebar's right border — the radial glow on .sp-admin-shell stays
          viewport-wide so both columns share the same light source.
        -->
        <div class="sp-admin-content flex min-w-0 flex-1 flex-col">
            <Topbar
                :title="title"
                :sidebar-collapsed="sidebarCollapsed"
                @toggle-sidebar="toggleSidebar"
                @open-palette="openPalette"
            />

            <main class="flex-1">
                <div
                    class="mx-auto w-full max-w-[1440px] px-7 py-[22px]"
                >
                    <slot />
                </div>
            </main>
        </div>

        <CommandPalette ref="paletteRef" />
    </div>
</template>
