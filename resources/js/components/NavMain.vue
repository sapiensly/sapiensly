<script setup lang="ts">
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { toUrl, urlIsActive } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    items: NavItem[];
    label?: string;
}>();

const { t } = useI18n();
const page = usePage();

// Pick the single item with the longest matching href so that nested paths
// (e.g. /admin/system/stack) don't also light up shorter parents (e.g. /admin).
const activeIndex = computed<number>(() => {
    let bestIndex = -1;
    let bestLength = -1;

    props.items.forEach((item, index) => {
        if (!urlIsActive(item.href, page.url)) {
            return;
        }
        const length = (toUrl(item.href) ?? '').length;
        if (length > bestLength) {
            bestLength = length;
            bestIndex = index;
        }
    });

    return bestIndex;
});
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>{{ label ?? t('nav.platform') }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem
                v-for="(item, index) in items"
                :key="item.title"
            >
                <SidebarMenuButton
                    as-child
                    :is-active="index === activeIndex"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
