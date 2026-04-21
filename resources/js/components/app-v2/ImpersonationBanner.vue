<script setup lang="ts">
import * as ImpersonateController from '@/actions/App/Http/Controllers/Admin/ImpersonateController';
import type { AppPageProps } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Eye, X } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage<AppPageProps>();

const active = computed(() => Boolean(page.props.impersonating));
const user = computed(() => page.props.auth?.user ?? null);

function stopImpersonating() {
    router.post(ImpersonateController.stop().url);
}
</script>

<template>
    <div
        v-if="active && user"
        role="status"
        aria-live="polite"
        class="sticky top-0 z-50 flex h-10 shrink-0 items-center justify-center gap-3 bg-sp-warning px-6 font-admin text-[12px] font-medium text-navy-deep"
    >
        <Eye class="size-3.5 shrink-0" />
        <span class="truncate">
            {{ t('impersonation.banner', { name: user.name, email: user.email }) }}
        </span>
        <button
            type="button"
            class="inline-flex shrink-0 items-center gap-1 rounded-pill border border-navy-deep/30 bg-navy-deep/15 px-2.5 py-0.5 text-[11px] font-semibold text-navy-deep transition-colors hover:bg-navy-deep/30"
            @click="stopImpersonating"
        >
            <X class="size-3" />
            {{ t('impersonation.stop') }}
        </button>
    </div>
</template>
