<script setup lang="ts">
import { Bell, Menu, Search } from '@/lib/admin/icons';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    title: string;
    /** Small notification badge count; 0 hides the dot. */
    notifications?: number;
    /** Passed down so the sidebar trigger label reflects state. */
    sidebarCollapsed?: boolean;
    /** True once the main content area has scrolled away from the top. */
    scrolled?: boolean;
}

withDefaults(defineProps<Props>(), {
    notifications: 0,
    sidebarCollapsed: false,
    scrolled: false,
});

const emit = defineEmits<{
    (e: 'toggle-sidebar'): void;
    (e: 'open-palette'): void;
}>();

const { t } = useI18n();

const modKey = computed(() =>
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/i.test(navigator.platform)
        ? '⌘'
        : 'Ctrl',
);
</script>

<!--
  Topbar per handoff/intented-layout-complete.png.
  Left: sidebar toggle (mobile) + breadcrumb.
  Right: search-style palette trigger (wide input), bell + badge.
  Sticky, 56px tall, 24px horizontal padding.
-->
<template>
    <header
        :class="[
            'sp-topbar sticky top-0 z-10 flex h-14 items-center gap-2 px-4 sm:gap-3 sm:px-6',
            scrolled && 'is-scrolled',
        ]"
    >
        <!--
          Sidebar collapse toggle — plain button (not shadcn's ghost Button,
          whose default hover paints with --accent and reads as white on
          this dark chrome). Matches the bell icon's treatment.
        -->
        <button
            type="button"
            class="flex size-9 shrink-0 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
            :aria-label="
                sidebarCollapsed
                    ? t('admin.topbar.expand_sidebar')
                    : t('admin.topbar.collapse_sidebar')
            "
            @click="emit('toggle-sidebar')"
        >
            <Menu class="size-4" />
        </button>

        <nav
            aria-label="Breadcrumb"
            class="flex items-center gap-1.5 text-sm text-ink-muted"
        >
            <!--
              The root crumb is the first thing to go below `sm`: it is the
              same word on every admin page, so it costs width and says
              nothing the title does not.
            -->
            <span class="hidden sm:inline">{{
                t('admin.breadcrumb.root')
            }}</span>
            <span class="hidden text-ink-faint sm:inline">/</span>
            <span class="font-medium text-ink">{{ title }}</span>
        </nav>

        <div class="flex-1" />

        <!--
          Search / command palette trigger — pill shape with a subtle
          accent-blue tint to match the reference. The ⌘K affordance inside
          is its own rounded pill for visual rhythm.

          Below `md` it collapses to the search icon alone: a 260px minimum
          does not fit beside the toggle and breadcrumb on a phone, and the
          fixed width pushed the bell and half this button off-screen. The
          placeholder and the ⌘K hint both go — there is no keyboard to press
          on the viewport that hides them.
        -->
        <button
            type="button"
            class="group flex h-9 w-9 shrink-0 items-center justify-center gap-2 rounded-pill border border-ink-muted/20 bg-surface text-[12px] text-ink-muted transition-colors hover:border-ink-muted/40 hover:text-ink md:w-auto md:min-w-[260px] md:justify-start md:pr-1.5 md:pl-3.5"
            :aria-label="t('admin.topbar.search_placeholder')"
            @click="emit('open-palette')"
        >
            <Search class="size-3.5 shrink-0 text-ink-muted" />
            <span class="hidden flex-1 text-left md:block">
                {{ t('admin.topbar.search_placeholder') }}
            </span>
            <kbd
                class="hidden shrink-0 rounded-pill border border-soft bg-surface-hover px-2 py-0.5 font-mono text-[10px] text-ink-subtle md:block"
            >
                {{ modKey }}K
            </kbd>
        </button>

        <!-- Notifications -->
        <button
            type="button"
            class="relative flex size-9 shrink-0 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
            :aria-label="t('admin.topbar.notifications')"
        >
            <Bell class="size-4" />
            <span
                v-if="notifications > 0"
                class="absolute top-1.5 right-1.5 flex size-4 items-center justify-center rounded-full bg-accent-blue font-mono text-[9px] font-semibold text-ink"
            >
                {{ notifications > 9 ? '9+' : notifications }}
            </span>
        </button>
    </header>
</template>
