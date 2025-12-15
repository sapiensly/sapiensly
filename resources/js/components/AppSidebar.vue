<script setup lang="ts">
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
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import * as KnowledgeBaseController from '@/actions/App/Http/Controllers/KnowledgeBaseController';
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { BookOpen, Bot, Database, FileText, Folder, LayoutGrid, Users, Wrench } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Agent Teams',
        href: AgentTeamController.index(),
        icon: Users,
    },
    {
        title: 'Agents',
        href: AgentController.index(),
        icon: Bot,
    },
    {
        title: 'Knowledge Base',
        href: KnowledgeBaseController.index(),
        icon: Database,
    },
    {
        title: 'Documents',
        href: DocumentController.index(),
        icon: FileText,
    },
    {
        title: 'Tools',
        href: ToolController.index(),
        icon: Wrench,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Github Repo',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
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
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
