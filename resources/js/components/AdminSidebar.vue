<script setup lang="ts">
import * as AccessSettingsController from '@/actions/App/Http/Controllers/Admin/AccessSettingsController';
import AdminDashboardController from '@/actions/App/Http/Controllers/Admin/AdminDashboardController';
import * as AdminUserController from '@/actions/App/Http/Controllers/Admin/AdminUserController';
import * as GlobalAiController from '@/actions/App/Http/Controllers/Admin/GlobalAiController';
import * as GlobalCloudController from '@/actions/App/Http/Controllers/Admin/GlobalCloudController';
import * as StackController from '@/actions/App/Http/Controllers/StackController';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BrainCircuit,
    Cloud,
    Layers,
    LayoutGrid,
    Shield,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogo from './AppLogo.vue';

const { t } = useI18n();

const adminNavItems = computed<NavItem[]>(() => [
    {
        title: 'Admin Dashboard',
        href: AdminDashboardController(),
        icon: LayoutGrid,
    },
    {
        title: 'Users',
        href: AdminUserController.index(),
        icon: Users,
    },
    {
        title: 'Access Settings',
        href: AccessSettingsController.index(),
        icon: Shield,
    },
    {
        title: t('admin.global_ai.title'),
        href: GlobalAiController.index(),
        icon: BrainCircuit,
    },
    {
        title: t('admin.global_cloud.title'),
        href: GlobalCloudController.index(),
        icon: Cloud,
    },
    {
        title: 'Stack',
        href: StackController.index(),
        icon: Layers,
    },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="AdminDashboardController()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="adminNavItems" label="Admin Panel" />

            <SidebarGroup class="mt-auto px-2">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton as-child tooltip="Back to App">
                            <Link :href="dashboard()">
                                <ArrowLeft />
                                <span>Back to App</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
