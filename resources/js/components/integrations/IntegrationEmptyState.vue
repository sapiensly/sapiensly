<script setup lang="ts">
import { INTEGRATION_TEMPLATES } from '@/lib/integrations/templates';
import { Link } from '@inertiajs/vue3';
import { Globe, Server } from '@lucide/vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Starter {
    key: string;
    label: string;
    description: string;
    href: string;
    icon: Component;
    tint: string;
}

// The honest "kinds" of connection you can start from: a credentialed HTTP API
// or an MCP server. Branded presets follow as quick-starts.
const kinds: Starter[] = [
    {
        key: 'rest',
        label: t('system.integrations.starter.rest_label'),
        description: t('system.integrations.starter.rest_desc'),
        href: '/system/integrations/create',
        icon: Globe,
        tint: 'var(--sp-accent-blue)',
    },
    {
        key: 'mcp',
        label: t('system.integrations.starter.mcp_label'),
        description: t('system.integrations.starter.mcp_desc'),
        href: '/system/integrations/create?kind=mcp',
        icon: Server,
        tint: 'var(--sp-success)',
    },
];

const templates: Starter[] = INTEGRATION_TEMPLATES.map((tpl) => ({
    key: `tpl-${tpl.slug}`,
    label: tpl.label,
    description: t(tpl.descriptionKey),
    href: `/system/integrations/create?template=${tpl.slug}`,
    icon: tpl.icon,
    tint: tpl.tint,
}));

const starters = [...kinds, ...templates];
</script>

<template>
    <div class="space-y-5">
        <div class="space-y-1">
            <h3 class="text-sm font-semibold text-ink">
                {{ t('system.integrations.starter.title') }}
            </h3>
            <p class="max-w-xl text-xs text-ink-muted">
                {{ t('system.integrations.starter.description') }}
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="starter in starters"
                :key="starter.key"
                :href="starter.href"
                class="flex cursor-pointer flex-col items-start gap-2 rounded-sp-sm border border-soft bg-white/[0.03] p-5 text-left transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
            >
                <div
                    class="flex size-9 items-center justify-center rounded-xs"
                    :style="{
                        backgroundColor: `color-mix(in oklab, ${starter.tint} 15%, transparent)`,
                        color: starter.tint,
                    }"
                >
                    <component :is="starter.icon" class="size-4" />
                </div>
                <h3 class="text-sm font-semibold text-ink">{{ starter.label }}</h3>
                <p class="text-xs text-ink-muted">{{ starter.description }}</p>
            </Link>
        </div>
    </div>
</template>
