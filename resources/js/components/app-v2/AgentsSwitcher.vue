<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Bot, Users } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{ active: 'agents' | 'teams' }>();

function go(path: string) {
    router.visit(path);
}
</script>

<template>
    <div class="inline-flex gap-1 rounded-xl border border-soft bg-surface p-1">
        <button
            type="button"
            :class="[
                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[13px] font-medium transition-colors',
                active === 'agents' ? 'bg-navy text-ink shadow-sm' : 'text-ink-muted hover:text-ink',
            ]"
            :aria-pressed="active === 'agents'"
            @click="go('/agents')"
        >
            <Bot class="size-3.5" />
            {{ t('app_v2.nav.agents') }}
        </button>
        <button
            type="button"
            :class="[
                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[13px] font-medium transition-colors',
                active === 'teams' ? 'bg-navy text-ink shadow-sm' : 'text-ink-muted hover:text-ink',
            ]"
            :aria-pressed="active === 'teams'"
            @click="go('/agent-teams')"
        >
            <Users class="size-3.5" />
            {{ t('app_v2.nav.agent_teams') }}
        </button>
    </div>
</template>
