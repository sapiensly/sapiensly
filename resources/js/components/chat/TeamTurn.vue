<script setup lang="ts">
import ActionCard from '@/components/chat/ActionCard.vue';
import AgentMessageBubble from '@/components/chat/AgentMessageBubble.vue';
import UserMessageBubble from '@/components/chat/UserMessageBubble.vue';
import type { Artifact } from '@/lib/artifacts';
import type {
    ChatAgentRef,
    ChatMessageDto,
    ChatSynthesisStatus,
    ConsultationDto,
    ToolActivityDto,
} from '@/types/chatModule';
import { ChevronDown, Loader2, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    // The turn's user message (the question being answered).
    user?: ChatMessageDto | null;
    // The agent responses + any system notices that make up the deliberation.
    deliberation: ChatMessageDto[];
    // The closing message: an action_proposal (the answer) or a system
    // "no recommendation" note. Null while the team is still deliberating.
    close?: ChatMessageDto | null;
    // The executed action's result, if the proposal was run.
    result?: ChatMessageDto | null;
    synthesisStatus?: ChatSynthesisStatus;
    actionBusy?: boolean;
    agents?: ChatAgentRef[];
    consultations?: Record<string, ConsultationDto[]>;
    toolActivity?: Record<string, ToolActivityDto[]>;
    activeArtifactId?: string | null;
    isLastTurn?: boolean;
}>();

const emit = defineEmits<{
    execute: [message: ChatMessageDto];
    dismiss: [message: ChatMessageDto];
    openArtifact: [artifact: Artifact];
}>();

const isProposal = computed(
    () => props.close?.message_type === 'action_proposal',
);
const isSystemClose = computed(
    () => !!props.close && props.close.message_type !== 'action_proposal',
);
// No close yet — the chain is still producing agent turns / synthesizing.
const deliberating = computed(() => !props.close);

const agentCount = computed(
    () => props.deliberation.filter((m) => m.agent_id).length,
);

function consultationsFor(m: ChatMessageDto): ConsultationDto[] {
    return props.consultations?.[m.id] ?? m.consultation_context ?? [];
}

// Expanded while the team is still deliberating; collapses once the answer
// lands (answer-first). A manual toggle wins from then on.
const userToggled = ref(false);
const open = ref(deliberating.value);
watch(
    () => deliberating.value,
    (busy) => {
        if (!userToggled.value) open.value = busy;
    },
);
function toggle() {
    userToggled.value = true;
    open.value = !open.value;
}
</script>

<template>
    <div class="space-y-7">
        <UserMessageBubble v-if="user" :message="user" :agents="agents" />

        <!-- Hero: the team's answer (or the still-deliberating state). -->
        <ActionCard
            v-if="isProposal && close"
            :message="close"
            :status="synthesisStatus"
            :busy="actionBusy"
            @execute="emit('execute', close)"
            @dismiss="emit('dismiss', close)"
        />

        <div v-else-if="isSystemClose" class="flex justify-center">
            <p
                class="rounded-full bg-white/5 px-3.5 py-1.5 text-center text-[12px] text-ink-subtle"
            >
                {{ close?.content }}
            </p>
        </div>

        <div
            v-else-if="deliberating"
            class="inline-flex items-center gap-2 rounded-2xl border border-accent-blue/30 bg-accent-blue/[0.06] px-4 py-2.5 text-sm text-accent-blue"
        >
            <Loader2 class="size-4 animate-spin" />
            {{ t('chat.team.deliberating') }}
        </div>

        <!-- Executed action result, if any. -->
        <div
            v-if="result?.content"
            class="rounded-2xl border border-sp-success/30 bg-sp-success/[0.08] p-3.5 text-sm whitespace-pre-wrap text-ink"
        >
            {{ result.content }}
        </div>

        <!-- Collapsible deliberation: how the team got there. -->
        <div v-if="deliberation.length">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full border border-medium bg-surface px-3 py-1.5 text-[13px] font-medium text-ink-muted transition-colors hover:border-strong hover:text-ink"
                :aria-expanded="open"
                @click="toggle"
            >
                <Users class="size-3.5 text-ink-subtle" />
                {{ t('chat.team.deliberation', { count: agentCount }) }}
                <ChevronDown
                    class="size-3.5 transition-transform"
                    :class="{ 'rotate-180': open }"
                />
            </button>

            <div v-if="open" class="mt-5 space-y-7">
                <template v-for="m in deliberation" :key="m.id">
                    <AgentMessageBubble
                        v-if="m.agent_id"
                        :message="m"
                        :consultations="consultationsFor(m)"
                        :tool-activity="
                            toolActivity ? toolActivity[m.id] : null
                        "
                        :active-artifact-id="activeArtifactId"
                        @open-artifact="emit('openArtifact', $event)"
                    />
                    <div v-else class="flex justify-center">
                        <p
                            class="rounded-full bg-white/5 px-3.5 py-1.5 text-center text-[12px] text-ink-subtle"
                        >
                            {{ m.content }}
                        </p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
