<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { toUrl, urlIsActive } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const hasOrganization = computed(() => !!page.props.auth.user.organization_id);

const sidebarNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: t('settings.nav.profile'),
            href: editProfile(),
        },
        {
            title: t('settings.nav.appearance'),
            href: editAppearance(),
        },
    ];

    if (hasOrganization.value) {
        items.push({
            title: t('settings.nav.organization'),
            href: '/settings/organization',
        });
    }

    return items;
});

const currentPath = typeof window !== undefined ? window.location.pathname : '';
</script>

<template>
    <AppLayoutV2 :title="t('settings.title')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="t('settings.title')"
                :description="t('settings.description')"
            />

            <div class="flex flex-col gap-6 lg:flex-row lg:gap-10">
                <!-- Settings sub-navigation — admin-v2 sidebar rhythm. -->
                <aside class="w-full shrink-0 lg:w-56">
                    <nav class="flex flex-col gap-1">
                        <Link
                            v-for="item in sidebarNavItems"
                            :key="toUrl(item.href)"
                            :href="item.href"
                            :class="[
                                'relative flex h-9 items-center gap-2 rounded-xs px-3 text-[13px] font-medium transition-colors',
                                urlIsActive(item.href, currentPath)
                                    ? 'bg-accent-blue/10 text-ink before:absolute before:top-2 before:bottom-2 before:left-0 before:w-0.5 before:bg-accent-blue before:content-[\'\']'
                                    : 'text-ink-muted hover:bg-white/5 hover:text-ink',
                            ]"
                        >
                            <component
                                v-if="item.icon"
                                :is="item.icon"
                                class="size-4 shrink-0"
                            />
                            <span class="truncate">{{ item.title }}</span>
                        </Link>
                    </nav>
                </aside>

                <div class="min-w-0 flex-1">
                    <slot />
                </div>
            </div>
        </div>
    </AppLayoutV2>
</template>
