<script setup lang="ts">
import Topbar from '@/components/app-v2/Topbar.vue';
import DebateConclusions from '@/components/debate/DebateConclusions.vue';
import DebateHeader from '@/components/debate/DebateHeader.vue';
import DebateRound from '@/components/debate/DebateRound.vue';
import DebateSetup from '@/components/debate/DebateSetup.vue';
import DebateSidebar from '@/components/debate/DebateSidebar.vue';
import echo from '@/echo';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type {
    ActiveDebateDto,
    ChatModelOption,
    DebateAgentOption,
    DebateListItem,
    DebateRoundDto,
    DebateTurnDto,
} from '@/types/debateModule';
import { Head, router } from '@inertiajs/vue3';
import { PanelLeftClose, PanelLeftOpen } from '@lucide/vue';
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    debates: DebateListItem[];
    models: ChatModelOption[];
    agents: DebateAgentOption[];
    defaultModel: string | null;
    defaultModerator: string | null;
    activeDebate: ActiveDebateDto | null;
}>();

const debate = ref<ActiveDebateDto | null>(
    structuredCloneSafe(props.activeDebate),
);
const scroller = ref<HTMLElement | null>(null);
const stuckToBottom = ref(true);

function structuredCloneSafe<T>(value: T): T {
    return value ? JSON.parse(JSON.stringify(value)) : value;
}

// ----- Inner debate sidebar (collapsible) -----
const SIDEBAR_KEY = 'debate:sidebar-open';
// Default closed for a focused landing; only an explicit stored `true`
// (the user opened it before) keeps it open. Their choice persists.
const debateSidebarOpen = ref(
    typeof window !== 'undefined' &&
        window.localStorage.getItem(SIDEBAR_KEY) === 'true',
);
watch(debateSidebarOpen, (open) => {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(SIDEBAR_KEY, String(open));
    }
});

const activeId = computed(() => debate.value?.id ?? null);

const busy = computed(() =>
    debate.value
        ? ['pending', 'debating', 'assessing', 'converged'].includes(
              debate.value.status,
          )
        : false,
);

const debateRounds = computed<DebateRoundDto[]>(() =>
    (debate.value?.rounds ?? [])
        .filter((r) => r.type !== 'synthesis')
        .sort((a, b) => a.round_number - b.round_number),
);

const synthesisRound = computed<DebateRoundDto | null>(
    () => debate.value?.rounds.find((r) => r.type === 'synthesis') ?? null,
);

const synthesisTurn = computed<DebateTurnDto | null>(() => {
    const r = synthesisRound.value;
    if (!r) return null;
    return r.turns.find((tn) => tn.role === 'moderator') ?? r.turns[0] ?? null;
});

const activeRoundNumber = computed(() => debate.value?.current_round ?? 0);

// ----- Turn / round mutation helpers -----
function findTurn(turnId: string): DebateTurnDto | null {
    for (const r of debate.value?.rounds ?? []) {
        const turn = r.turns.find((tn) => tn.id === turnId);
        if (turn) return turn;
    }
    return null;
}

function upsertRound(round: DebateRoundDto) {
    if (!debate.value) return;
    const idx = debate.value.rounds.findIndex((r) => r.id === round.id);
    if (idx === -1) {
        debate.value.rounds = [...debate.value.rounds, round].sort(
            (a, b) => a.round_number - b.round_number,
        );
    } else {
        debate.value.rounds[idx] = round;
    }
}

// ----- Echo streaming -----
type ChannelHandle = ReturnType<typeof echo.private>;
let channel: ChannelHandle | null = null;
let subscribedId: string | null = null;

function subscribe(id: string) {
    unsubscribe();
    subscribedId = id;
    channel = echo.private(`debate.${id}`);

    channel.listen(
        '.DebateTurnChunk',
        (data: { turn_id: string; delta: string }) => {
            const turn = findTurn(data.turn_id);
            if (turn) {
                turn.content = (turn.content ?? '') + data.delta;
                turn.status = 'streaming';
                maybeScroll();
            }
        },
    );

    channel.listen(
        '.DebateTurnComplete',
        (payload: { turn: DebateTurnDto }) => {
            const turn = findTurn(payload.turn.id);
            if (turn) Object.assign(turn, payload.turn);
            maybeScroll();
        },
    );

    channel.listen(
        '.DebateTurnError',
        (data: { turn_id: string; error: string }) => {
            const turn = findTurn(data.turn_id);
            if (turn) {
                turn.status = 'error';
                turn.error = data.error;
            }
        },
    );

    channel.listen(
        '.DebateRoundStarted',
        (payload: { round: DebateRoundDto }) => {
            upsertRound(payload.round);
            nextTick(() => scrollToBottom('smooth'));
        },
    );

    channel.listen(
        '.DebateRoundAssessed',
        (payload: { round: DebateRoundDto }) => {
            upsertRound(payload.round);
            maybeScroll();
        },
    );

    channel.listen(
        '.DebateStatusChanged',
        (data: {
            status: ActiveDebateDto['status'];
            current_round: number;
            max_rounds: number;
            consensus_reached: boolean;
            consensus_score: number | null;
        }) => {
            if (!debate.value) return;
            debate.value.status = data.status;
            debate.value.current_round = data.current_round;
            debate.value.max_rounds = data.max_rounds;
            debate.value.consensus_reached = data.consensus_reached;
            debate.value.consensus_score = data.consensus_score;
        },
    );

    channel.listen(
        '.DebateComplete',
        (payload: { debate: ActiveDebateDto }) => {
            debate.value = payload.debate;
            router.reload({ only: ['debates'] });
            nextTick(() => scrollToBottom('smooth'));
        },
    );
}

function unsubscribe() {
    if (channel && subscribedId) {
        channel.stopListening('.DebateTurnChunk');
        channel.stopListening('.DebateTurnComplete');
        channel.stopListening('.DebateTurnError');
        channel.stopListening('.DebateRoundStarted');
        channel.stopListening('.DebateRoundAssessed');
        channel.stopListening('.DebateStatusChanged');
        channel.stopListening('.DebateComplete');
        echo.leave(`debate.${subscribedId}`);
        channel = null;
        subscribedId = null;
    }
}

watch(
    () => props.activeDebate?.id,
    (id) => {
        debate.value = structuredCloneSafe(props.activeDebate);
        stuckToBottom.value = true;
        if (id) {
            subscribe(id);
            nextTick(() => scrollToBottom('instant'));
        } else {
            unsubscribe();
        }
    },
    { immediate: true },
);

onBeforeUnmount(unsubscribe);

// ----- Scrolling -----
function onScroll() {
    const el = scroller.value;
    if (!el) return;
    stuckToBottom.value =
        el.scrollHeight - el.scrollTop - el.clientHeight < 120;
}

function scrollToBottom(behavior: ScrollBehavior = 'smooth') {
    nextTick(() => {
        const el = scroller.value;
        if (el) el.scrollTo({ top: el.scrollHeight, behavior });
    });
}

function maybeScroll() {
    if (stuckToBottom.value) scrollToBottom('smooth');
}

// ----- Stop -----
function stop() {
    if (!activeId.value || !debate.value) return;
    axios.post(`/debates/${activeId.value}/stop`).catch(() => {});
    debate.value.status = 'stopped';
}
</script>

<template>
    <Head :title="debate?.title || debate?.topic || t('app_v2.nav.debate')" />

    <AppLayoutV2
        v-slot="{ openPalette, toggleSidebar, sidebarCollapsed }"
        :title="t('app_v2.nav.debate')"
        bg="flat"
        :full-bleed="true"
        hide-topbar
    >
        <div class="flex min-h-0 flex-1">
            <div
                :class="[
                    'hidden shrink-0 overflow-hidden transition-[width] duration-200 ease-in-out md:block',
                    debateSidebarOpen ? 'w-72' : 'w-0',
                ]"
            >
                <DebateSidebar :debates="debates" :active-id="activeId" />
            </div>

            <div class="flex min-h-0 flex-1 flex-col">
                <Topbar
                    :title="t('app_v2.nav.debate')"
                    :sidebar-collapsed="sidebarCollapsed"
                    @toggle-sidebar="toggleSidebar"
                    @open-palette="openPalette"
                >
                    <template #leading>
                        <button
                            type="button"
                            class="hidden size-9 shrink-0 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink md:flex"
                            :aria-label="
                                debateSidebarOpen
                                    ? t('debate.hide_sidebar')
                                    : t('debate.show_sidebar')
                            "
                            @click="debateSidebarOpen = !debateSidebarOpen"
                        >
                            <PanelLeftClose
                                v-if="debateSidebarOpen"
                                class="size-4"
                            />
                            <PanelLeftOpen v-else class="size-4" />
                        </button>
                    </template>
                </Topbar>

                <template v-if="debate">
                    <DebateHeader
                        :topic="debate.topic"
                        :title="debate.title"
                        :status="debate.status"
                        :current-round="debate.current_round"
                        :max-rounds="debate.max_rounds"
                        :consensus-score="debate.consensus_score"
                        :consensus-reached="debate.consensus_reached"
                        :busy="busy"
                        @stop="stop"
                    />

                    <div
                        ref="scroller"
                        class="flex-1 overflow-y-auto"
                        @scroll.passive="onScroll"
                    >
                        <div
                            class="mx-auto w-full max-w-[1100px] space-y-4 px-6 py-6"
                        >
                            <DebateRound
                                v-for="r in debateRounds"
                                :key="r.id"
                                :round="r"
                                :participants="debate.participants"
                                :default-open="
                                    r.round_number === activeRoundNumber ||
                                    r.round_number === debateRounds.length
                                "
                            />

                            <DebateConclusions
                                v-if="synthesisRound"
                                :turn="synthesisTurn"
                                :participants="debate.participants"
                            />
                        </div>
                    </div>
                </template>

                <DebateSetup
                    v-else
                    :models="models"
                    :agents="agents"
                    :default-model="defaultModel"
                    :default-moderator="defaultModerator"
                />
            </div>
        </div>
    </AppLayoutV2>
</template>
