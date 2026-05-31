<script setup lang="ts">
import '@/../css/admin.css';

import CommandPalette from '@/components/app-v2/CommandPalette.vue';
import ImpersonationBanner from '@/components/app-v2/ImpersonationBanner.vue';
import Sidebar from '@/components/app-v2/Sidebar.vue';
import Topbar from '@/components/app-v2/Topbar.vue';
import { useLocaleSync } from '@/composables/useLocale';
import type { AppPageProps } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

useLocaleSync();

interface Props {
    title: string;
    /** Background treatment — defaults to 'blueprint' per layout_spec §2. */
    bg?: 'blueprint' | 'flat' | 'spectrum' | 'subtle';
    /**
     * Skip the default padded/max-width wrapper so the page slot fills the
     * content area edge-to-edge. Required for canvas / chat / editor surfaces
     * (flow editor, agent chat) that need the full viewport height.
     */
    fullBleed?: boolean;
    /**
     * Force the sidebar collapsed on mount regardless of the shared
     * `sidebar_state` cookie. Pass `true` for canvas / editor pages that
     * need every pixel of horizontal space (e.g. the flow editor).
     * The user can still toggle open via the topbar button; that toggle
     * writes the cookie like any other page so their preference persists
     * once they leave the editor.
     */
    forceCollapsedOnMount?: boolean;
    /**
     * Omit the shared Topbar so a full-bleed page can render its own header
     * (e.g. Chat puts a slim header only over the conversation, letting its
     * left panel reach the top). The page receives `openPalette` /
     * `toggleSidebar` via the default slot to drive the command palette and
     * the main sidebar from its own header.
     */
    hideTopbar?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    bg: 'blueprint',
    fullBleed: false,
    forceCollapsedOnMount: false,
    hideTopbar: false,
});

// Shared cookie with legacy /admin and /admin so sidebar collapse state
// persists across all three surfaces. When `forceCollapsedOnMount` is set,
// start collapsed without writing to the cookie — the user's persistent
// preference is preserved for when they leave the editor.
const page = usePage<AppPageProps>();
const initialOpen = (page.props.sidebarOpen ?? true) as boolean;
const sidebarCollapsed = ref(props.forceCollapsedOnMount ? true : !initialOpen);
const impersonating = computed(() => Boolean(page.props.impersonating));

const paletteRef = ref<InstanceType<typeof CommandPalette> | null>(null);

const scrolled = ref(false);
function onMainScroll(event: Event) {
    scrolled.value = (event.target as HTMLElement).scrollTop > 0;
}

function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    const open = !sidebarCollapsed.value;
    document.cookie = `sidebar_state=${open}; path=/; max-age=${60 * 60 * 24 * 7}`;
}

function openPalette() {
    if (paletteRef.value) paletteRef.value.open = true;
}
</script>

<template>
    <div class="flex h-screen flex-col overflow-hidden">
        <ImpersonationBanner />

        <div
            class="sp-app-shell flex flex-1 min-h-0"
            :class="{ 'is-impersonating': impersonating }"
            :data-bg="bg"
        >
            <Sidebar :collapsed="sidebarCollapsed" class="hidden lg:flex" />

            <div class="sp-app-content flex min-w-0 flex-1 flex-col">
                <!--
                  Topbar lives INSIDE `main` so the scroll context belongs to
                  the same element. That lets the topbar's sticky positioning
                  pin to the top of the scroll container and the scrolling
                  content passes *behind* it, which is what makes
                  `backdrop-filter: blur(...)` actually show frosted glass.
                  For `fullBleed` pages main is not a scroll container; the
                  topbar simply sits at the top as a regular flex item.
                -->
                <main
                    :class="['flex min-h-0 flex-1 flex-col', !fullBleed && 'overflow-y-auto']"
                    @scroll.passive="onMainScroll"
                >
                    <Topbar
                        v-if="!hideTopbar"
                        :title="title"
                        :sidebar-collapsed="sidebarCollapsed"
                        :scrolled="scrolled"
                        @toggle-sidebar="toggleSidebar"
                        @open-palette="openPalette"
                    />

                    <div
                        v-if="fullBleed"
                        class="flex min-h-0 flex-1 flex-col"
                    >
                        <slot
                            :open-palette="openPalette"
                            :toggle-sidebar="toggleSidebar"
                            :sidebar-collapsed="sidebarCollapsed"
                        />
                    </div>
                    <div
                        v-else
                        class="mx-auto w-full max-w-[1440px] px-7 py-[22px]"
                    >
                        <slot
                            :open-palette="openPalette"
                            :toggle-sidebar="toggleSidebar"
                            :sidebar-collapsed="sidebarCollapsed"
                        />
                    </div>
                </main>
            </div>
        </div>

        <CommandPalette ref="paletteRef" />
    </div>
</template>
