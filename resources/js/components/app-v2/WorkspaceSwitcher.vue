<script setup lang="ts">
import CouncilIcon from '@/components/icons/CouncilIcon.vue';
import { router } from '@inertiajs/vue3';
import { MessagesSquare } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{ active: 'chat' | 'debate' }>();

function go(path: string) {
    router.visit(path);
}
</script>

<template>
    <div class="border-t border-soft p-2">
        <div class="flex gap-1 rounded-xl bg-surface p-1">
            <button
                type="button"
                :class="[
                    'flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-medium transition-colors',
                    active === 'chat'
                        ? 'bg-navy text-ink shadow-sm'
                        : 'text-ink-muted hover:text-ink',
                ]"
                :aria-pressed="active === 'chat'"
                @click="go('/chat')"
            >
                <MessagesSquare class="size-3.5" />
                {{ t('app_v2.nav.chat') }}
            </button>
            <button
                type="button"
                :class="[
                    'flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-medium transition-colors',
                    active === 'debate'
                        ? 'bg-navy text-ink shadow-sm'
                        : 'text-ink-muted hover:text-ink',
                ]"
                :aria-pressed="active === 'debate'"
                @click="go('/debates')"
            >
                <CouncilIcon class="size-3.5" />
                {{ t('app_v2.nav.debate') }}
            </button>
        </div>
    </div>
</template>
