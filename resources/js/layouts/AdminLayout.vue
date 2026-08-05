<script setup lang="ts">
import '@/../css/admin.css';

import CommandPalette from '@/components/admin/CommandPalette.vue';
import Sidebar from '@/components/admin/Sidebar.vue';
import Topbar from '@/components/admin/Topbar.vue';
import ImpersonationBanner from '@/components/app-v2/ImpersonationBanner.vue';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { useIsDesktop } from '@/composables/useIsDesktop';
import { useLocaleSync } from '@/composables/useLocale';
import type { AppPageProps } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

useLocaleSync();

const { t } = useI18n();

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
const impersonating = computed(() => Boolean(page.props.impersonating));

const paletteRef = ref<InstanceType<typeof CommandPalette> | null>(null);

const scrolled = ref(false);
function onMainScroll(event: Event) {
    scrolled.value = (event.target as HTMLElement).scrollTop > 0;
}

// Below `lg` the sidebar column is not rendered, so the same affordance has to
// mean something else: open the off-canvas drawer instead of collapsing a
// column that isn't there. Until this existed the toggle was inert on a phone
// and admin had no navigation at all below 1024px.
const isDesktop = useIsDesktop();
const mobileNavOpen = ref(false);

function toggleSidebar() {
    if (!isDesktop.value) {
        mobileNavOpen.value = !mobileNavOpen.value;
        return;
    }

    sidebarCollapsed.value = !sidebarCollapsed.value;
    const open = !sidebarCollapsed.value;
    // 7-day rolling cookie, path-wide so the legacy admin sees it too.
    document.cookie = `sidebar_state=${open}; path=/; max-age=${60 * 60 * 24 * 7}`;
}

// Navigating from inside the drawer must dismiss it — Inertia swaps the page
// component without unmounting the persistent layout, so nothing else would.
const stopNavigateListener = router.on('navigate', () => {
    mobileNavOpen.value = false;
});
onUnmounted(() => stopNavigateListener());

// Widening past `lg` reveals the real sidebar; leaving the drawer mounted would
// strand its overlay over the page.
watch(isDesktop, (desktop) => {
    if (desktop) {
        mobileNavOpen.value = false;
    }
});

function openPalette() {
    if (paletteRef.value) paletteRef.value.open = true;
}
</script>

<template>
    <div class="flex h-screen flex-col overflow-hidden">
        <ImpersonationBanner />

        <div
            class="sp-admin-shell flex min-h-0 flex-1"
            :class="{ 'is-impersonating': impersonating }"
            :data-bg="bg"
        >
            <!--
              Sidebar sticks to its own column and spans the full viewport height.
              Collapsing to a 64px icon rail is triggered from the Topbar via the
              toggle button (same cookie + behaviour as the legacy /admin shell).
            -->
            <Sidebar :collapsed="sidebarCollapsed" class="hidden lg:flex" />

            <!--
              Same Sidebar, off-canvas, for viewports below `lg`. Rendering the
              one component keeps a single nav definition — a second mobile-only
              menu would drift out of sync the first time a section is added.
              It always renders expanded: a 64px icon rail behind an overlay
              would be a worse target than the labels it hides.
              (`.sp-mobile-nav` in admin.css already skinned this; only the
              wiring was missing.)
            -->
            <Sheet v-model:open="mobileNavOpen">
                <SheetContent
                    side="left"
                    class="sp-mobile-nav w-60 max-w-[85vw] gap-0 border-0 p-0"
                >
                    <SheetTitle class="sr-only">
                        {{ t('admin.sidebar.mobile_nav_title') }}
                    </SheetTitle>
                    <Sidebar :collapsed="false" />
                </SheetContent>
            </Sheet>

            <!--
              Content column owns the grid overlay so the 50px lines stop at the
              sidebar's right border — the radial glow on .sp-admin-shell stays
              viewport-wide so both columns share the same light source.
            -->
            <div class="sp-admin-content flex min-w-0 flex-1 flex-col">
                <!--
                  Topbar lives INSIDE `main` so it shares its scroll context.
                  Sticky top-0 then pins the topbar to the top of main and the
                  scrolling content passes *behind* it — required for the
                  topbar's `backdrop-filter: blur(...)` to actually show
                  frosted glass.
                -->
                <main
                    class="flex-1 overflow-y-auto"
                    @scroll.passive="onMainScroll"
                >
                    <Topbar
                        :title="title"
                        :sidebar-collapsed="sidebarCollapsed"
                        :scrolled="scrolled"
                        @toggle-sidebar="toggleSidebar"
                        @open-palette="openPalette"
                    />

                    <div class="mx-auto w-full max-w-[1440px] px-7 py-[22px]">
                        <slot />
                    </div>
                </main>
            </div>
        </div>

        <CommandPalette ref="paletteRef" />
    </div>
</template>
