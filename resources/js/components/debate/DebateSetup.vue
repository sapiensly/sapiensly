<script setup lang="ts">
import CouncilIcon from '@/components/icons/CouncilIcon.vue';
import type { ChatModelOption, DebateAgentOption } from '@/types/debateModule';
import { router } from '@inertiajs/vue3';
import { Check, ChevronDown, Sparkles, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        models: ChatModelOption[];
        defaultModel: string | null;
        defaultModerator: string | null;
        agents?: DebateAgentOption[];
    }>(),
    { agents: () => [] },
);

const MIN_PARTICIPANTS = 2;
const MAX_PARTICIPANTS = 9;
const BRAND = '#0059ff';

const topic = ref('');
const selected = ref<string[]>([]);
const moderator = ref<string | null>(
    props.defaultModerator ?? props.models[0]?.value ?? null,
);
const rounds = ref(3);
const submitting = ref(false);
const open = ref(false);

interface ParticipantOption {
    value: string;
    name: string;
    mark: string;
    accent: string;
}

interface OptionGroup {
    key: string;
    label: string;
    mark: string;
    accent: string;
    items: ParticipantOption[];
}

function providerAccent(provider: string): string {
    const p = (provider ?? '').toLowerCase();
    if (p.includes('anthropic')) return '#d97757';
    if (p.includes('openai')) return '#10a37f';
    if (p.includes('google') || p.includes('gemini')) return '#4285f4';
    if (p.includes('mistral')) return '#fa520f';
    if (p.includes('cohere')) return '#39594d';
    return BRAND;
}

const groupedModels = computed(() => {
    const map = new Map<string, ChatModelOption[]>();
    for (const m of props.models) {
        if (!map.has(m.provider)) map.set(m.provider, []);
        map.get(m.provider)!.push(m);
    }
    return [...map.entries()];
});

const optionGroups = computed<OptionGroup[]>(() => {
    const groups: OptionGroup[] = [];

    if (props.agents.length) {
        groups.push({
            key: 'agents',
            label: t('debate.setup.agents'),
            mark: 'AI',
            accent: BRAND,
            items: props.agents.map((a) => ({
                value: `agent:${a.id}`,
                name: a.name,
                mark: (a.name.trim()[0] ?? 'A').toUpperCase(),
                accent: BRAND,
            })),
        });
    }

    for (const [provider, items] of groupedModels.value) {
        groups.push({
            key: `provider:${provider}`,
            label: provider,
            mark: (provider.trim()[0] ?? '·').toUpperCase(),
            accent: providerAccent(provider),
            items: items.map((m) => ({
                value: m.value,
                name: m.label,
                mark: (provider.trim()[0] ?? '·').toUpperCase(),
                accent: providerAccent(provider),
            })),
        });
    }

    return groups;
});

const optionByValue = computed<Record<string, ParticipantOption>>(() => {
    const map: Record<string, ParticipantOption> = {};
    for (const g of optionGroups.value) {
        for (const o of g.items) map[o.value] = o;
    }
    return map;
});

const selectedOptions = computed(() =>
    selected.value.map((v) => optionByValue.value[v]).filter(Boolean),
);

const moderatorLabel = computed(
    () =>
        props.models.find((m) => m.value === moderator.value)?.label ??
        t('debate.setup.moderator'),
);

const counterOk = computed(
    () =>
        selected.value.length >= MIN_PARTICIPANTS &&
        selected.value.length <= MAX_PARTICIPANTS,
);
const canStart = computed(
    () => topic.value.trim().length > 0 && counterOk.value && !submitting.value,
);

const roundsFill = computed(() => ((rounds.value - 1) / 4) * 100);

function isSelected(value: string): boolean {
    return selected.value.includes(value);
}

function toggle(value: string) {
    if (isSelected(value)) {
        selected.value = selected.value.filter((v) => v !== value);
    } else if (selected.value.length < MAX_PARTICIPANTS) {
        selected.value = [...selected.value, value];
    }
}

function start() {
    if (!canStart.value) return;
    submitting.value = true;
    router.post(
        '/debates',
        {
            topic: topic.value.trim(),
            model_ids: selected.value,
            moderator_model: moderator.value,
            max_rounds: rounds.value,
        },
        { onFinish: () => (submitting.value = false) },
    );
}
</script>

<template>
    <div class="flex-1 overflow-y-auto">
        <div class="mx-auto w-full max-w-[740px] px-7 pt-12 pb-20">
            <!-- Hero -->
            <header class="mb-9 text-center">
                <span
                    class="mx-auto mb-5 flex size-[60px] items-center justify-center rounded-[20px] bg-[#0059ff]/10"
                >
                    <CouncilIcon class="size-7 text-[#0059ff]" />
                </span>
                <h1
                    class="mb-2 text-[30px] leading-tight font-bold tracking-tight text-ink"
                >
                    {{ t('debate.setup.heading') }}
                </h1>
                <p class="text-base text-ink-muted">
                    {{ t('debate.setup.subheading') }}
                </p>
            </header>

            <!-- Problem -->
            <section class="mb-7">
                <div
                    class="rounded-[20px] border border-medium bg-navy p-5 shadow-sm transition-all focus-within:border-[#0059ff]/50 focus-within:shadow-[0_0_0_4px_rgba(0,89,255,0.10)]"
                >
                    <textarea
                        v-model="topic"
                        :placeholder="t('debate.setup.topic_placeholder')"
                        class="block max-h-64 min-h-24 w-full resize-none bg-transparent text-base leading-relaxed text-ink placeholder:text-ink-subtle focus:outline-none"
                    />
                </div>
            </section>

            <!-- Participants -->
            <section class="mb-7">
                <div class="mb-3 flex items-baseline justify-between px-1">
                    <h2 class="text-base font-semibold text-ink">
                        {{ t('debate.setup.pick_models') }}
                    </h2>
                    <span
                        :class="[
                            'rounded-full border px-3 py-[5px] text-[13px] font-semibold transition-colors',
                            counterOk
                                ? 'border-[#0059ff]/30 bg-[#0059ff]/10 text-[#0059ff]'
                                : 'border-medium bg-surface text-ink-muted',
                        ]"
                    >
                        {{
                            t('debate.setup.selected_count', {
                                count: selected.length,
                            })
                        }}
                    </span>
                </div>

                <!-- Multi-select combobox -->
                <div class="relative">
                    <div
                        v-if="open"
                        class="fixed inset-0 z-20"
                        @click="open = false"
                    />

                    <button
                        type="button"
                        :class="[
                            'relative flex min-h-[56px] w-full flex-wrap items-center gap-2 rounded-[14px] border bg-navy py-2.5 pr-12 pl-3.5 text-left shadow-sm transition-all',
                            open
                                ? 'border-[#0059ff]/50 shadow-[0_0_0_4px_rgba(0,89,255,0.10)]'
                                : 'border-medium hover:border-strong',
                        ]"
                        @click="open = !open"
                    >
                        <template v-if="selectedOptions.length">
                            <span
                                v-for="o in selectedOptions"
                                :key="o.value"
                                class="inline-flex items-center gap-1.5 rounded-full border border-[#0059ff]/25 bg-[#0059ff]/10 py-1 pr-1.5 pl-2 text-[13px] font-medium text-ink"
                            >
                                <span
                                    class="flex size-4 items-center justify-center rounded-[5px] text-[9px] font-bold text-white"
                                    :style="{ backgroundColor: o.accent }"
                                    >{{ o.mark }}</span
                                >
                                {{ o.name }}
                                <span
                                    role="button"
                                    tabindex="0"
                                    class="flex size-[17px] items-center justify-center rounded-full bg-[#0059ff]/15 text-[#0059ff] transition-colors hover:bg-[#0059ff]/25"
                                    @click.stop="toggle(o.value)"
                                    @keydown.enter.stop="toggle(o.value)"
                                >
                                    <X class="size-2.5" :stroke-width="2.6" />
                                </span>
                            </span>
                        </template>
                        <span v-else class="py-1 text-[15px] text-ink-subtle">
                            {{ t('debate.setup.select_placeholder') }}
                        </span>
                        <ChevronDown
                            :class="[
                                'absolute top-[18px] right-4 size-[17px] text-ink-subtle transition-transform',
                                open && 'rotate-180',
                            ]"
                        />
                    </button>

                    <!-- Panel -->
                    <Transition
                        enter-active-class="transition duration-150"
                        enter-from-class="-translate-y-1.5 opacity-0"
                        leave-active-class="transition duration-100"
                        leave-to-class="-translate-y-1.5 opacity-0"
                    >
                        <div
                            v-if="open"
                            class="absolute top-[calc(100%+8px)] right-0 left-0 z-30 max-h-[340px] overflow-y-auto rounded-2xl border border-medium bg-navy p-2 shadow-[0_8px_30px_rgba(20,28,55,0.18)]"
                        >
                            <p
                                v-if="!models.length && !agents.length"
                                class="px-3 py-4 text-center text-xs text-ink-subtle"
                            >
                                {{ t('debate.setup.no_models') }}
                            </p>
                            <div
                                v-for="g in optionGroups"
                                :key="g.key"
                                class="px-2 pt-2.5 pb-1 first:pt-1"
                            >
                                <div class="mb-2 flex items-center gap-2 px-1">
                                    <span
                                        class="flex size-[22px] items-center justify-center rounded-[7px] text-[12px] font-bold text-white"
                                        :style="{ backgroundColor: g.accent }"
                                        >{{ g.mark }}</span
                                    >
                                    <span
                                        class="text-[12px] font-semibold tracking-wide text-ink-muted uppercase"
                                        >{{ g.label }}</span
                                    >
                                </div>
                                <button
                                    v-for="o in g.items"
                                    :key="o.value"
                                    type="button"
                                    :disabled="
                                        !isSelected(o.value) &&
                                        selected.length >= MAX_PARTICIPANTS
                                    "
                                    :class="[
                                        'flex w-full items-center gap-3 rounded-xl px-2.5 py-2.5 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-40',
                                        isSelected(o.value)
                                            ? 'bg-[#0059ff]/10'
                                            : 'hover:bg-surface',
                                    ]"
                                    @click="toggle(o.value)"
                                >
                                    <span
                                        :class="[
                                            'flex size-5 shrink-0 items-center justify-center rounded-md border transition-all',
                                            isSelected(o.value)
                                                ? 'border-[#0059ff] bg-[#0059ff] text-white'
                                                : 'border-medium text-transparent',
                                        ]"
                                    >
                                        <Check
                                            class="size-3"
                                            :stroke-width="3.2"
                                        />
                                    </span>
                                    <span
                                        class="text-[14.5px] font-semibold text-ink"
                                        >{{ o.name }}</span
                                    >
                                </button>
                            </div>
                        </div>
                    </Transition>
                </div>
            </section>

            <!-- Settings -->
            <section class="grid gap-6 sm:grid-cols-2">
                <div>
                    <p class="mb-3 text-base font-semibold text-ink">
                        {{ t('debate.setup.moderator') }}
                    </p>
                    <div class="relative">
                        <Sparkles
                            class="pointer-events-none absolute top-1/2 left-4 size-[18px] -translate-y-1/2 text-[#0059ff]"
                        />
                        <select
                            v-model="moderator"
                            :aria-label="moderatorLabel"
                            class="h-[50px] w-full appearance-none rounded-[13px] border border-medium bg-navy pr-10 pl-11 text-[15px] font-medium text-ink shadow-sm transition-all outline-none hover:border-strong focus:border-[#0059ff]/50 focus:shadow-[0_0_0_4px_rgba(0,89,255,0.10)]"
                        >
                            <option
                                v-for="m in models"
                                :key="m.value"
                                :value="m.value"
                            >
                                {{ m.label }}
                            </option>
                        </select>
                        <ChevronDown
                            class="pointer-events-none absolute top-1/2 right-3.5 size-4 -translate-y-1/2 text-ink-subtle"
                        />
                    </div>
                    <p class="mt-2.5 px-0.5 text-[13px] text-ink-subtle">
                        {{ t('debate.setup.moderator_hint') }}
                    </p>
                </div>

                <div>
                    <div class="flex items-baseline justify-between">
                        <p class="mb-3 text-base font-semibold text-ink">
                            {{ t('debate.setup.rounds') }}
                        </p>
                        <span
                            class="text-lg font-bold text-[#0059ff] tabular-nums"
                            >{{ rounds }}</span
                        >
                    </div>
                    <div class="px-1 pt-2">
                        <input
                            v-model.number="rounds"
                            type="range"
                            min="1"
                            max="5"
                            step="1"
                            class="h-1.5 w-full cursor-pointer appearance-none rounded-full accent-[#0059ff]"
                            :style="{
                                background: `linear-gradient(to right, #0059ff 0 ${roundsFill}%, var(--color-medium, #e4e6ee) ${roundsFill}% 100%)`,
                            }"
                        />
                        <div
                            class="mt-3 flex justify-between px-1.5 text-[11.5px] font-medium text-ink-subtle tabular-nums"
                        >
                            <span>1</span><span>2</span><span>3</span
                            ><span>4</span><span>5</span>
                        </div>
                    </div>
                    <p class="mt-2.5 px-0.5 text-[13px] text-ink-subtle">
                        {{ t('debate.setup.rounds_hint') }}
                    </p>
                </div>
            </section>

            <!-- CTA -->
            <div class="mt-8">
                <button
                    type="button"
                    :disabled="!canStart"
                    class="flex w-full items-center justify-center gap-2.5 rounded-2xl bg-[#0059ff] px-4 py-[17px] text-base font-semibold text-white shadow-[0_10px_26px_rgba(0,89,255,0.28)] transition-all hover:bg-[#0049d6] active:translate-y-px disabled:cursor-not-allowed disabled:bg-medium disabled:text-ink-subtle disabled:shadow-none"
                    @click="start"
                >
                    <CouncilIcon class="size-5" />
                    {{ t('debate.setup.start')
                    }}<span v-if="counterOk"> · {{ selected.length }}</span>
                </button>
                <p class="mt-3 text-center text-[13px] text-ink-subtle">
                    {{
                        counterOk
                            ? t('debate.setup.cta_hint_ready')
                            : t('debate.setup.cta_hint_min')
                    }}
                </p>
            </div>
        </div>
    </div>
</template>
