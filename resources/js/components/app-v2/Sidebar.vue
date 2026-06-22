<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import * as AiProviderController from '@/actions/App/Http/Controllers/AiProviderController';
import * as AppController from '@/actions/App/Http/Controllers/AppController';
import * as ChatController from '@/actions/App/Http/Controllers/ChatController';
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import * as CloudProviderController from '@/actions/App/Http/Controllers/CloudProviderController';
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import * as IntegrationController from '@/actions/App/Http/Controllers/IntegrationController';
import * as KnowledgeBaseController from '@/actions/App/Http/Controllers/KnowledgeBaseController';
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
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
import { usePermissions } from '@/composables/usePermissions';
import { dashboard } from '@/routes';
import type { AppPageProps } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    AppWindow,
    Bot,
    BrainCircuit,
    ChevronsUpDown,
    Cloud,
    Database,
    FileText,
    FlaskConical,
    KeyRound,
    LayoutGrid,
    MessageCircle,
    MessageSquare,
    MessagesSquare,
    Plug,
    Shield,
    Wallet,
    Wrench,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface NavItem {
    key: string;
    label: string;
    href: string;
    icon: Component;
    match: (url: string) => boolean;
}

interface NavSection {
    key: string;
    label: string;
    items: NavItem[];
}

withDefaults(
    defineProps<{
        collapsed?: boolean;
    }>(),
    { collapsed: false },
);

const { t } = useI18n();
const page = usePage<AppPageProps>();
const { isSysAdmin } = usePermissions();

const currentUrl = computed(() => page.url);

// The brand block stays pinned above the scrolling nav; reveal a bottom
// border only once that nav is scrolled away from the top, mirroring the
// topbar's `.is-scrolled` treatment.
const navScrolled = ref(false);
function onNavScroll(event: Event): void {
    navScrolled.value = (event.target as HTMLElement).scrollTop > 0;
}

const dashboardHref = computed(() => dashboard().url);

const sections = computed<NavSection[]>(() => [
    {
        key: 'main',
        label: t('app_v2.sidebar.section_main'),
        items: [
            {
                key: 'chat',
                label: t('app_v2.nav.chat'),
                href: ChatController.index().url,
                icon: MessagesSquare,
                match: (u) =>
                    u === '/chat' ||
                    u.startsWith('/chat/') ||
                    u === '/debates' ||
                    u.startsWith('/debates/'),
            },
            {
                key: 'playground',
                label: t('app_v2.nav.playground'),
                href: '/playground',
                icon: FlaskConical,
                match: (u) => u.startsWith('/playground'),
            },
            {
                key: 'apps',
                label: t('app_v2.nav.apps'),
                href: AppController.index().url,
                icon: AppWindow,
                match: (u) => u.startsWith('/apps') || u.startsWith('/r/'),
            },
            {
                key: 'chatbots',
                label: t('app_v2.nav.chatbots'),
                href: ChatbotController.index().url,
                icon: MessageSquare,
                match: (u) => u.startsWith('/chatbots'),
            },
        ],
    },
    {
        key: 'capabilities',
        label: t('app_v2.sidebar.section_capabilities'),
        items: [
            {
                key: 'agents',
                label: t('app_v2.nav.agents'),
                href: AgentController.index().url,
                icon: Bot,
                match: (u) => u.startsWith('/agents'),
            },
            {
                key: 'tools',
                label: t('app_v2.nav.tools'),
                href: ToolController.index().url,
                icon: Wrench,
                match: (u) => u.startsWith('/tools'),
            },
            {
                key: 'documents',
                label: t('app_v2.nav.documents'),
                href: DocumentController.index().url,
                icon: FileText,
                match: (u) => u.startsWith('/documents'),
            },
            {
                key: 'knowledge-bases',
                label: t('app_v2.nav.knowledge_base'),
                href: KnowledgeBaseController.index().url,
                icon: Database,
                match: (u) => u.startsWith('/knowledge-bases'),
            },
        ],
    },
    {
        key: 'system',
        label: t('app_v2.sidebar.section_system'),
        items: [
            {
                key: 'dashboard',
                label: t('app_v2.nav.dashboard'),
                href: dashboardHref.value,
                icon: LayoutGrid,
                match: (u) =>
                    u === dashboardHref.value ||
                    u.startsWith(`${dashboardHref.value}?`),
            },
            {
                key: 'ai-providers',
                label: t('app_v2.nav.ai_providers'),
                href: AiProviderController.index().url,
                icon: BrainCircuit,
                match: (u) => u.startsWith('/system/ai-providers'),
            },
            {
                key: 'ai-spend',
                label: t('app_v2.nav.ai_spend'),
                href: '/system/ai-spend',
                icon: Wallet,
                match: (u) => u.startsWith('/system/ai-spend'),
            },
            {
                key: 'cloud-providers',
                label: t('app_v2.nav.cloud_providers'),
                href: CloudProviderController.index().url,
                icon: Cloud,
                match: (u) => u.startsWith('/system/cloud-providers'),
            },
            {
                key: 'integrations',
                label: t('app_v2.nav.integrations'),
                href: IntegrationController.index().url,
                icon: Plug,
                match: (u) => u.startsWith('/system/integrations'),
            },
            {
                key: 'mcp',
                label: t('app_v2.nav.mcp'),
                href: '/system/mcp',
                icon: KeyRound,
                match: (u) => u.startsWith('/system/mcp'),
            },
            {
                key: 'whatsapp',
                label: t('app_v2.nav.whatsapp'),
                href: '/system/whatsapp',
                icon: MessageCircle,
                match: (u) => u.startsWith('/system/whatsapp'),
            },
        ],
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

const hasOrganization = computed(() => Boolean(page.props.auth?.organization));

const workspaceLabel = computed(() =>
    hasOrganization.value
        ? page.props.auth.organization!.name
        : t('app_v2.breadcrumb.personal'),
);
</script>

<template>
    <aside
        :class="[
            'sp-glass-sidebar flex h-full shrink-0 flex-col transition-[width] duration-[180ms] ease-[cubic-bezier(0.4,0,0.2,1)]',
            collapsed ? 'w-16' : 'w-60',
        ]"
    >
        <!-- Brand block — same 56px height as the topbar. Border appears only
             once the nav below is scrolled (matches the topbar on scroll). -->
        <div
            :class="[
                'flex h-14 items-center border-b transition-colors',
                navScrolled ? 'border-soft' : 'border-transparent',
                collapsed ? 'justify-center px-3' : 'gap-2 px-5',
            ]"
        >
            <Link
                :href="ChatController.index().url"
                class="flex items-center gap-1 outline-none"
            >
                <AppLogo tone="white" :collapsed="collapsed" />
            </Link>
        </div>

        <!--
          Nav — grouped sections (Dashboard now lives under System).
        -->
        <nav
            :class="[
                'flex-1 overflow-y-auto py-6',
                collapsed ? 'px-2' : 'px-5',
            ]"
            @scroll.passive="onNavScroll"
        >
            <TooltipProvider :delay-duration="120" disable-closing-trigger>
                <!-- Grouped sections. -->
                <div
                    v-for="(section, sectionIndex) in sections"
                    :key="section.key"
                    :class="sectionIndex === 0 ? '' : 'mt-6'"
                >
                    <p
                        v-if="!collapsed"
                        class="mb-3 px-3 text-[10px] font-semibold tracking-[0.18em] text-ink-faint uppercase"
                    >
                        {{ section.label }}
                    </p>
                    <ul class="space-y-1">
                        <li v-for="item in section.items" :key="item.key">
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
                                                  : 'text-ink-muted hover:bg-surface hover:text-ink',
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
                                <TooltipContent side="right" :side-offset="8">
                                    {{ item.label }}
                                </TooltipContent>
                            </Tooltip>
                        </li>
                    </ul>
                </div>
            </TooltipProvider>
        </nav>

        <!-- Bottom: sysadmin shortcut to /admin + user card. -->
        <div class="shrink-0">
            <Link
                v-if="isSysAdmin()"
                href="/admin"
                :class="[
                    'flex items-center gap-3 px-5 py-5 text-[13px] font-medium text-ink-muted transition-colors hover:bg-surface hover:text-ink',
                    collapsed ? 'justify-center px-0' : '',
                ]"
            >
                <Shield class="size-4 shrink-0" />
                <span v-if="!collapsed">
                    {{ t('app_v2.sidebar.admin_panel') }}
                </span>
            </Link>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        :class="[
                            'flex w-full items-center gap-2 px-5 py-4 text-left text-sm leading-tight transition-colors hover:bg-surface data-[state=open]:bg-surface',
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
                            <span class="truncate font-medium text-ink">
                                {{ authUser?.name ?? '—' }}
                            </span>
                            <span class="truncate text-xs text-ink-subtle">
                                {{ workspaceLabel
                                }}<template v-if="hasOrganization">
                                    · {{ userRole }}</template
                                >
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
