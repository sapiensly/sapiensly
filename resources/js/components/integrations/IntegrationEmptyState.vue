<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plug, Plus } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-3 rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
    >
        <div
            class="flex size-12 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
        >
            <Plug class="size-5" />
        </div>
        <div class="space-y-1">
            <h3 class="text-sm font-semibold text-ink">
                {{ t('system.integrations.empty_title') }}
            </h3>
            <p class="mx-auto max-w-md text-xs text-ink-muted">
                {{ t('system.integrations.empty_description') }}
            </p>
        </div>
        <Link href="/system/integrations/create">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
            >
                <Plus class="size-3.5" />
                {{ t('system.integrations.empty_cta') }}
            </button>
        </Link>
    </div>
</template>
