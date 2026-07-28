<script setup lang="ts">
import { useIsDesktop } from '@/composables/useIsDesktop';
import type { AppPageProps } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { Bell, Menu, Search } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    title: string;
    notifications?: number;
    sidebarCollapsed?: boolean;
    scrolled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    notifications: 0,
    sidebarCollapsed: false,
    scrolled: false,
});

const emit = defineEmits<{
    (e: 'toggle-sidebar'): void;
    (e: 'open-palette'): void;
}>();

const { t } = useI18n();
const page = usePage<AppPageProps>();

const workspaceLabel = computed(
    () => page.props.auth.organization?.name ?? t('app_v2.breadcrumb.personal'),
);

// Below `lg` the button opens the nav drawer rather than collapsing a column,
// so the accessible name has to say so — the icon alone is the same either way.
const isDesktop = useIsDesktop();

const sidebarButtonLabel = computed(() => {
    if (!isDesktop.value) {
        return t('app_v2.topbar.open_nav');
    }

    return props.sidebarCollapsed
        ? t('app_v2.topbar.expand_sidebar')
        : t('app_v2.topbar.collapse_sidebar');
});

const modKey = computed(() =>
    typeof navigator !== 'undefined' && /Mac|iPhone|iPad/i.test(navigator.platform)
        ? '⌘'
        : 'Ctrl',
);
</script>

<template>
    <header
        :class="[
            'sp-topbar sticky top-0 z-10 flex h-14 shrink-0 items-center gap-2 px-4 sm:gap-3 sm:px-6',
            scrolled && 'is-scrolled',
        ]"
    >
        <button
            type="button"
            class="flex size-9 shrink-0 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
            :aria-label="sidebarButtonLabel"
            @click="emit('toggle-sidebar')"
        >
            <Menu class="size-4" />
        </button>

        <slot name="leading" />

        <!--
          On narrow viewports the workspace segment is dropped rather than
          squeezed: the page title is what tells you where you are, and the
          workspace is one tap away in the drawer's user card.
        -->
        <nav
            aria-label="Breadcrumb"
            class="flex min-w-0 items-center gap-1.5 text-sm text-ink-muted"
        >
            <span class="hidden sm:inline">{{ workspaceLabel }}</span>
            <span class="hidden text-ink-faint sm:inline">/</span>
            <span class="truncate font-medium text-ink">{{ title }}</span>
        </nav>

        <div class="flex-1" />

        <button
            type="button"
            class="group flex h-9 w-9 shrink-0 items-center justify-center gap-2 rounded-pill border border-ink-muted/20 bg-surface text-[12px] text-ink-muted transition-colors hover:border-ink-muted/40 hover:text-ink md:w-auto md:min-w-[260px] md:justify-start md:pr-1.5 md:pl-3.5"
            :aria-label="t('app_v2.topbar.search_placeholder')"
            @click="emit('open-palette')"
        >
            <Search class="size-3.5 shrink-0 text-ink-muted" />
            <!--
              The label and the shortcut hint collapse into the icon below `md`:
              a 260px pill plus the breadcrumb does not fit a phone, and the
              keyboard hint is meaningless without a keyboard.
            -->
            <span class="hidden flex-1 text-left md:block">
                {{ t('app_v2.topbar.search_placeholder') }}
            </span>
            <kbd
                class="hidden shrink-0 rounded-pill border border-soft bg-surface-hover px-2 py-0.5 font-mono text-[10px] text-ink-subtle md:block"
            >
                {{ modKey }}K
            </kbd>
        </button>

        <button
            type="button"
            class="relative flex size-9 shrink-0 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
            :aria-label="t('app_v2.topbar.notifications')"
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
