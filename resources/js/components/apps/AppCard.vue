<script setup lang="ts">
import * as AppController from '@/actions/App/Http/Controllers/AppController';
import { Link } from '@inertiajs/vue3';
import { AppWindow, Building2, Globe, History, Lock, Sparkles } from 'lucide-vue-next';
import { computed, type Component } from 'vue';
import { useI18n } from 'vue-i18n';

interface AppCardData {
    id: string;
    slug: string;
    name: string;
    description: string | null;
    icon: string | null;
    color: string | null;
    visibility: string;
    created_at: string;
    current_version?: {
        id: string;
        version_number: number;
        created_at: string;
    } | null;
}

const props = defineProps<{ app: AppCardData }>();

const { t } = useI18n();

const tint = props.app.color ?? 'var(--sp-accent-blue)';

interface PillStyle {
    icon: Component;
    classes: string;
    label: string;
}

const visibilityPill = computed<PillStyle>(() => {
    switch (props.app.visibility) {
        case 'organization':
            return {
                icon: Building2,
                classes: 'border-accent-blue/30 bg-accent-blue/10 text-accent-blue',
                label: 'Org',
            };
        case 'public':
            return {
                icon: Globe,
                classes: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300',
                label: 'Public',
            };
        case 'global':
            return {
                icon: Globe,
                classes: 'border-spectrum-magenta/30 bg-spectrum-magenta/10 text-spectrum-magenta',
                label: 'Global',
            };
        default:
            return {
                icon: Lock,
                classes: 'border-medium bg-surface text-ink-muted',
                label: 'Private',
            };
    }
});

const versionPill = computed<PillStyle>(() => {
    if (props.app.current_version) {
        return {
            icon: History,
            classes: 'border-soft bg-surface text-ink-muted',
            label: `v${props.app.current_version.version_number}`,
        };
    }
    return {
        icon: Sparkles,
        classes: 'border-amber-400/30 bg-amber-400/10 text-amber-300',
        label: t('apps.index.no_version'),
    };
});
</script>

<template>
    <Link :href="AppController.show(app.id).url" class="block">
        <article
            class="group flex h-full flex-col gap-3 rounded-sp-sm border border-soft bg-navy p-5 transition-colors hover:border-accent-blue/40"
        >
            <header class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-3 min-w-0">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xs"
                        :style="{
                            backgroundColor: `color-mix(in oklab, ${tint} 15%, transparent)`,
                            color: tint,
                        }"
                    >
                        <AppWindow class="size-4" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="truncate text-sm font-semibold text-ink">
                            {{ app.name }}
                        </h3>
                        <p class="mt-0.5 truncate font-mono text-[11px] text-ink-subtle">
                            /r/{{ app.slug }}
                        </p>
                    </div>
                </div>

                <span
                    :class="[
                        'inline-flex shrink-0 items-center gap-1 rounded-pill border px-2 py-0.5 text-[10px] uppercase tracking-wider',
                        visibilityPill.classes,
                    ]"
                >
                    <component :is="visibilityPill.icon" class="size-3" />
                    {{ visibilityPill.label }}
                </span>
            </header>

            <p
                v-if="app.description"
                class="line-clamp-2 text-xs text-ink-muted"
            >
                {{ app.description }}
            </p>

            <footer class="mt-auto flex items-center justify-between">
                <span
                    :class="[
                        'inline-flex items-center gap-1 rounded-pill border px-2 py-0.5 text-[10px] uppercase tracking-wider',
                        versionPill.classes,
                    ]"
                >
                    <component :is="versionPill.icon" class="size-3" />
                    {{ versionPill.label }}
                </span>
            </footer>
        </article>
    </Link>
</template>
