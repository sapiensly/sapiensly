<script setup lang="ts">
import AdminDashboardController from '@/actions/App/Http/Controllers/Admin/AdminDashboardController';
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import * as AiProviderController from '@/actions/App/Http/Controllers/AiProviderController';
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import * as CloudProviderController from '@/actions/App/Http/Controllers/CloudProviderController';
import * as IntegrationController from '@/actions/App/Http/Controllers/IntegrationController';
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import * as FlowController from '@/actions/App/Http/Controllers/FlowController';
import * as KnowledgeBaseController from '@/actions/App/Http/Controllers/KnowledgeBaseController';
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/composables/usePermissions';
import { urlIsActive } from '@/lib/utils';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    Bot,
    BrainCircuit,
    Cloud,
    Database,
    FileText,
    GitBranch,
    LayoutGrid,
    MessageSquare,
    Plug,
    Shield,
    Users,
    Wrench,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogo from './AppLogo.vue';

const { t } = useI18n();
const page = usePage();
const { isSysAdmin } = usePermissions();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: t('nav.flows'),
        href: FlowController.globalIndex(),
        icon: GitBranch,
    },
    {
        title: t('nav.chatbots'),
        href: ChatbotController.index(),
        icon: MessageSquare,
    },
    {
        title: t('nav.agents'),
        href: AgentController.index(),
        icon: Bot,
    },
    {
        title: t('nav.agent_teams'),
        href: AgentTeamController.index(),
        icon: Users,
    },
]);

const capabilitiesNavItems = computed<NavItem[]>(() => [
    {
        title: t('nav.tools'),
        href: ToolController.index(),
        icon: Wrench,
    },
    {
        title: t('nav.documents'),
        href: DocumentController.index(),
        icon: FileText,
    },
    {
        title: t('nav.knowledge_base'),
        href: KnowledgeBaseController.index(),
        icon: Database,
    },
]);

const systemNavItems = computed<NavItem[]>(() => [
    {
        title: t('nav.ai_providers'),
        href: AiProviderController.index(),
        icon: BrainCircuit,
    },
    {
        title: t('nav.cloud_providers'),
        href: CloudProviderController.index(),
        icon: Cloud,
    },
    {
        title: t('nav.integrations'),
        href: IntegrationController.index(),
        icon: Plug,
    },
]);

const footerNavItems = computed<NavItem[]>(() => [
    {
        title: t('nav.documentation'),
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <SidebarMenu class="px-2 py-2">
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        :is-active="urlIsActive(dashboard(), page.url)"
                        :tooltip="t('nav.dashboard')"
                    >
                        <Link :href="dashboard()">
                            <LayoutGrid />
                            <span>{{ t('nav.dashboard') }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavMain :items="mainNavItems" />
            <NavMain
                :items="capabilitiesNavItems"
                :label="t('nav.capabilities')"
            />
            <NavMain :items="systemNavItems" :label="t('nav.system')" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu v-if="isSysAdmin()" class="px-2">
                <SidebarMenuItem>
                    <SidebarMenuButton as-child tooltip="Admin Panel" class="bg-primary text-primary-foreground hover:bg-primary/90 hover:text-primary-foreground">
                        <Link :href="AdminDashboardController()">
                            <Shield />
                            <span>Admin Panel</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
