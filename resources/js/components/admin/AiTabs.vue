<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Shared tab header for the three AI sub-pages. Each tab is a distinct
 * Inertia route so deep-linking works. Active state reads the current URL.
 */
interface Props {
    current: 'defaults' | 'catalog' | 'usage';
}

defineProps<Props>();
const { t } = useI18n();

const tabs = computed(() => [
    {
        key: 'defaults' as const,
        label: t('admin.ai.tabs.defaults'),
        href: '/admin/ai',
    },
    {
        key: 'catalog' as const,
        label: t('admin.ai.tabs.catalog'),
        href: '/admin/ai/catalog',
    },
    {
        key: 'usage' as const,
        label: t('admin.ai.tabs.usage'),
        href: '/admin/ai/usage',
    },
]);
</script>

<template>
    <nav
        class="flex items-center gap-5 border-b border-soft"
        role="tablist"
        aria-label="AI sections"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            :class="[
                'relative px-1 pb-3 text-sm transition-colors outline-none',
                tab.key === current
                    ? 'text-ink after:absolute after:bottom-[-1px] after:left-0 after:h-[2px] after:w-full after:bg-accent-blue after:content-[\'\']'
                    : 'text-ink-muted hover:text-ink',
            ]"
            :aria-selected="tab.key === current"
            role="tab"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
