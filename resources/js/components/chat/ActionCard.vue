<script setup lang="ts">
import type { ChatMessageDto, ChatSynthesisStatus } from '@/types/chatModule';
import { CircleCheck, Loader2, Sparkles, X } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    message: ChatMessageDto;
    // Thread-level synthesis status drives the executed/locked state.
    status?: ChatSynthesisStatus;
    busy?: boolean;
}>();

defineEmits<{ execute: []; dismiss: [] }>();

const payload = computed(() => props.message.action_payload);
const parameters = computed(() =>
    Object.entries(payload.value?.parameters ?? {}),
);
const executed = computed(() => props.status === 'executed');

function initials(name: string): string {
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('');
}
</script>

<template>
    <div
        v-if="payload"
        class="overflow-hidden rounded-2xl border border-accent-blue/30 bg-accent-blue/[0.06] shadow-sm"
    >
        <div class="flex items-center justify-between gap-2 px-4 pt-3.5">
            <span
                class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue/15 px-2.5 py-0.5 text-[10px] font-bold tracking-wider text-accent-blue uppercase"
            >
                <Sparkles class="size-3" />
                {{ t('chat.action.badge') }}
            </span>
            <button
                v-if="!executed"
                type="button"
                :title="t('chat.action.dismiss')"
                class="rounded p-1 text-ink-subtle transition-colors hover:bg-white/10 hover:text-ink"
                @click="$emit('dismiss')"
            >
                <X class="size-4" />
            </button>
        </div>

        <div class="px-4 pt-2 pb-4">
            <h3 class="text-[15px] font-semibold text-ink">
                {{ payload.action_label }}
            </h3>

            <!-- Agreed by -->
            <div
                v-if="payload.agreed_by?.length"
                class="mt-2 flex items-center gap-2"
            >
                <span class="text-[11px] text-ink-subtle">{{
                    t('chat.action.agreed_by')
                }}</span>
                <div class="flex -space-x-1.5">
                    <span
                        v-for="agentName in payload.agreed_by"
                        :key="agentName"
                        :title="agentName"
                        class="flex size-6 items-center justify-center rounded-full border border-surface bg-accent-blue/20 text-[10px] font-semibold text-accent-blue"
                    >
                        {{ initials(agentName) }}
                    </span>
                </div>
            </div>

            <!-- Parameter pills -->
            <div v-if="parameters.length" class="mt-3 flex flex-wrap gap-1.5">
                <span
                    v-for="[key, value] in parameters"
                    :key="key"
                    class="inline-flex items-center gap-1 rounded-lg border border-medium bg-surface px-2 py-1 text-[11px] text-ink"
                >
                    <span class="text-ink-subtle">{{ key }}:</span>
                    <span class="font-medium">{{ value }}</span>
                </span>
            </div>

            <p
                v-if="payload.rationale"
                class="mt-3 text-[12px] leading-relaxed text-ink-muted"
            >
                {{ payload.rationale }}
            </p>

            <!-- Actions -->
            <div class="mt-4 flex items-center gap-2">
                <div
                    v-if="executed"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-sp-success/15 px-3 py-2 text-sm font-medium text-sp-success"
                >
                    <CircleCheck class="size-4" />
                    {{ t('chat.action.executed') }}
                </div>
                <button
                    v-else
                    type="button"
                    :disabled="busy"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-accent-blue px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
                    @click="$emit('execute')"
                >
                    <Loader2 v-if="busy" class="size-4 animate-spin" />
                    {{
                        busy
                            ? t('chat.action.executing')
                            : t('chat.action.execute')
                    }}
                </button>
            </div>
        </div>
    </div>
</template>
