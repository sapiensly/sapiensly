<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    Back,
    NavAccess,
    NavAi,
    NavCloud,
    NavDashboard,
    NavStack,
    NavUsers,
} from '@/lib/admin/icons';
import { ChevronsUpDown } from 'lucide-vue-next';
import type { AppPageProps } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface NavItem {
    key: string;
    label: string;
    href: string;
    icon: Component;
    match: (url: string) => boolean;
}

withDefaults(
    defineProps<{
        collapsed?: boolean;
    }>(),
    { collapsed: false },
);

const { t } = useI18n();
const page = usePage<AppPageProps>();

const currentUrl = computed(() => page.url);

const navItems = computed<NavItem[]>(() => [
    {
        key: 'dashboard',
        label: t('admin_v2.nav.dashboard'),
        href: '/admin2',
        icon: NavDashboard,
        match: (u) => u === '/admin2' || u.startsWith('/admin2?'),
    },
    {
        key: 'users',
        label: t('admin_v2.nav.users'),
        href: '/admin2/users',
        icon: NavUsers,
        match: (u) => u.startsWith('/admin2/users'),
    },
    {
        key: 'access',
        label: t('admin_v2.nav.access'),
        href: '/admin2/access',
        icon: NavAccess,
        match: (u) => u.startsWith('/admin2/access'),
    },
    {
        key: 'ai',
        label: t('admin_v2.nav.ai_full'),
        href: '/admin2/ai',
        icon: NavAi,
        match: (u) => u.startsWith('/admin2/ai'),
    },
    {
        key: 'cloud',
        label: t('admin_v2.nav.cloud_full'),
        href: '/admin2/cloud',
        icon: NavCloud,
        match: (u) => u.startsWith('/admin2/cloud'),
    },
    {
        key: 'stack',
        label: t('admin_v2.nav.stack'),
        href: '/admin2/stack',
        icon: NavStack,
        match: (u) => u.startsWith('/admin2/stack'),
    },
]);

function isActive(item: NavItem): boolean {
    return item.match(currentUrl.value);
}

const authUser = computed(() => page.props.auth?.user ?? null);

const userInitials = computed(() => {
    const name = authUser.value?.name ?? '';
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((n) => n[0]?.toUpperCase())
            .join('') || '·'
    );
});

const userRole = computed(() => {
    const roles = page.props.auth?.roles ?? [];
    return roles[0] ?? 'member';
});
</script>

<!--
  Sidebar per handoff/intented-layout-complete.png.

  Top: logo mark + "sapiensly" wordmark + "ADMIN CONSOLE" eyebrow.
  Then: "ADMIN PANEL" section label above the nav group.
  Nav items: 36px tall; active uses a subtle white/5 tile + 2px blue left bar
  (not a bright blue block).
  Bottom (pinned): "Back to App" link + user identity card.
  Opaque bg-navy — blueprint treatment on body stops at the edge.
-->
<template>
    <aside
        :class="[
            'sp-glass-sidebar sticky top-0 z-10 flex h-screen shrink-0 flex-col transition-[width] duration-[180ms] ease-[cubic-bezier(0.4,0,0.2,1)]',
            collapsed ? 'w-16' : 'w-60',
        ]"
    >
        <!--
          Brand block — reuses the shared AppLogo used by /admin/users so the
          admin-v2 chrome matches the legacy admin exactly. 56px tall so it
          aligns with the topbar on the body side.
        -->
        <div
            :class="[
                'flex h-14 items-center border-b border-soft',
                collapsed ? 'justify-center px-3' : 'gap-2 px-5',
            ]"
        >
            <Link
                href="/admin2"
                class="flex items-center gap-2 outline-none"
            >
                <AppLogo tone="white" :collapsed="collapsed" />
            </Link>
        </div>

        <!--
          Nav — horizontal padding collapses so the 36×36 icon buttons still
          fit inside the 64px rail width (w-16). When expanded, 20px padding
          provides the spec'd breathing room.
        -->
        <nav
            :class="[
                'flex-1 overflow-y-auto py-6',
                collapsed ? 'px-2' : 'px-5',
            ]"
        >
            <p
                v-if="!collapsed"
                class="mb-3 px-3 text-[10px] font-semibold tracking-[0.18em] text-[#ffffff40] uppercase"
            >
                {{ t('admin_v2.sidebar.section_admin') }}
            </p>
            <!--
              Nav items have two visual modes:
              - Expanded: full-width tile (icon + label), 2px accent-blue
                left bar when active (per layout_spec §1).
              - Collapsed: fixed-size square button centered in the 64px
                column, bg tint + accent-blue icon colour when active. No
                left bar — it would hug the sidebar edge awkwardly at this
                width and the icon-only shape reads as active on its own.
            -->
            <!--
              In collapsed mode each icon gets a shadcn Tooltip on hover that
              exposes the nav label. Tooltips are gated on `collapsed` via
              `disable-hoverable-content`/`disableHoverableContent` on the
              provider — expanded mode shows the label inline and doesn't
              need the popover.
            -->
            <TooltipProvider :delay-duration="120" disable-closing-trigger>
                <ul class="space-y-1">
                    <li v-for="item in navItems" :key="item.key">
                        <Tooltip :disabled="!collapsed">
                            <TooltipTrigger as-child>
                                <Link
                                    :href="item.href"
                                    :class="[
                                        'relative flex items-center rounded-xs text-[13px] font-medium transition-colors',
                                        collapsed
                                            ? 'mx-auto size-9 justify-center'
                                            : 'h-9 gap-3 px-3',
                                        isActive(item) && !collapsed
                                            ? 'bg-accent-blue/10 text-ink before:absolute before:top-2 before:bottom-2 before:left-0 before:w-0.5 before:bg-accent-blue before:content-[\'\']'
                                            : isActive(item) && collapsed
                                              ? 'bg-accent-blue/10 text-accent-blue'
                                              : 'text-ink-muted hover:bg-white/5 hover:text-ink',
                                    ]"
                                >
                                    <component
                                        :is="item.icon"
                                        class="size-4 shrink-0"
                                    />
                                    <span
                                        v-if="!collapsed"
                                        class="truncate"
                                    >
                                        {{ item.label }}
                                    </span>
                                </Link>
                            </TooltipTrigger>
                            <TooltipContent
                                side="right"
                                :side-offset="8"
                                class="border-soft bg-navy text-ink"
                            >
                                {{ item.label }}
                            </TooltipContent>
                        </Tooltip>
                    </li>
                </ul>
            </TooltipProvider>
        </nav>

        <!-- Bottom: Back to app + user card -->
        <div class="shrink-0">
            <!--
              "Back to App" per intented-layout-complete.png — no top divider;
              the only line sits BELOW the row, separating it from the user card.
            -->
            <Link
                href="/dashboard"
                :class="[
                    'flex items-center gap-3 border-b border-soft px-5 py-5 text-[13px] font-medium text-ink-muted transition-colors hover:bg-white/5 hover:text-ink',
                    collapsed ? 'justify-center px-0' : '',
                ]"
            >
                <Back class="size-4 shrink-0" />
                <span v-if="!collapsed">
                    {{ t('admin_v2.sidebar.back_to_app') }}
                </span>
            </Link>

            <!--
              User identity card — opens the same account menu (Personal,
              organizations, Settings, Log out) that the legacy /admin shell
              shows via NavUser. We reuse UserMenuContent directly rather
              than re-implementing the dropdown here.
            -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        :class="[
                            'flex w-full items-center gap-2 px-5 py-4 text-left text-sm leading-tight transition-colors hover:bg-white/5 data-[state=open]:bg-white/5',
                            collapsed ? 'justify-center px-0' : '',
                        ]"
                    >
                        <Avatar class="size-8 shrink-0">
                            <AvatarFallback
                                class="bg-sp-success text-xs font-semibold text-ink"
                            >
                                {{ userInitials }}
                            </AvatarFallback>
                        </Avatar>
                        <div
                            v-if="!collapsed"
                            class="grid min-w-0 flex-1 leading-tight"
                        >
                            <span
                                class="truncate font-medium text-ink"
                            >
                                {{ authUser?.name ?? '—' }}
                            </span>
                            <span class="truncate text-xs text-ink-subtle">
                                {{ userRole }}
                            </span>
                        </div>
                        <ChevronsUpDown
                            v-if="!collapsed"
                            class="ml-auto size-4 shrink-0 text-ink-subtle"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    side="top"
                    align="end"
                    :side-offset="8"
                    class="sp-admin-menu min-w-56 rounded-lg"
                >
                    <UserMenuContent v-if="authUser" :user="authUser" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </aside>
</template>
