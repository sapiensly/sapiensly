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
}

withDefaults(defineProps<Props>(), {
    notifications: 0,
    sidebarCollapsed: false,
});

const emit = defineEmits<{
    (e: 'toggle-sidebar'): void;
    (e: 'open-palette'): void;
}>();

const { t } = useI18n();

const modKey = computed(() =>
    typeof navigator !== 'undefined' && /Mac|iPhone|iPad/i.test(navigator.platform)
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
        class="sp-glass sticky top-0 z-10 flex h-14 items-center gap-3 px-6"
    >
        <!--
          Sidebar collapse toggle — plain button (not shadcn's ghost Button,
          whose default hover paints with --accent and reads as white on
          this dark chrome). Matches the bell icon's treatment.
        -->
        <button
            type="button"
            class="flex size-9 shrink-0 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-white/5 hover:text-ink"
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
            <span>{{ t('admin.breadcrumb.root') }}</span>
            <span class="text-ink-faint">/</span>
            <span class="font-medium text-ink">{{ title }}</span>
        </nav>

        <div class="flex-1" />

        <!--
          Search / command palette trigger — pill shape with a subtle
          accent-blue tint to match the reference. The ⌘K affordance inside
          is its own rounded pill for visual rhythm.
        -->
        <button
            type="button"
            class="group flex h-9 min-w-[260px] items-center gap-2 rounded-pill border border-ink-muted/20 bg-black/40 pr-1.5 pl-3.5 text-[12px] text-ink-muted transition-colors hover:border-ink-muted/40 hover:text-ink"
            @click="emit('open-palette')"
        >
            <Search class="size-3.5 shrink-0 text-ink-muted" />
            <span class="flex-1 text-left">
                {{ t('admin.topbar.search_placeholder') }}
            </span>
            <kbd
                class="shrink-0 rounded-pill border border-soft bg-black/50 px-2 py-0.5 font-mono text-[10px] text-ink-subtle"
            >
                {{ modKey }}K
            </kbd>
        </button>

        <!-- Notifications -->
        <button
            type="button"
            class="relative flex size-9 shrink-0 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-white/5 hover:text-ink"
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
