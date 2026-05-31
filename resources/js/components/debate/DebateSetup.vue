<script setup lang="ts">
import DebateIcon from '@/components/icons/DebateIcon.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { ChatModelOption, DebateAgentOption } from '@/types/debateModule';
import { router } from '@inertiajs/vue3';
import { Bot, Check, ChevronDown, Loader2, Sparkles } from 'lucide-vue-next';
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

const topic = ref('');
const selected = ref<string[]>([]);
const moderator = ref<string | null>(
    props.defaultModerator ?? props.models[0]?.value ?? null,
);
const rounds = ref(3);
const submitting = ref(false);

const grouped = computed(() => {
    const map = new Map<string, ChatModelOption[]>();
    for (const m of props.models) {
        if (!map.has(m.provider)) map.set(m.provider, []);
        map.get(m.provider)!.push(m);
    }
    return [...map.entries()];
});

const moderatorLabel = computed(
    () =>
        props.models.find((m) => m.value === moderator.value)?.label ??
        t('debate.setup.moderator'),
);

const MAX_PARTICIPANTS = 9;

const canStart = computed(
    () =>
        topic.value.trim().length > 0 &&
        selected.value.length >= 2 &&
        selected.value.length <= MAX_PARTICIPANTS &&
        !submitting.value,
);

function toggle(value: string) {
    if (selected.value.includes(value)) {
        selected.value = selected.value.filter((v) => v !== value);
    } else if (selected.value.length < MAX_PARTICIPANTS) {
        selected.value = [...selected.value, value];
    }
}

const suggestions = computed<string[]>(() => [
    t('debate.setup.suggestion_1'),
    t('debate.setup.suggestion_2'),
    t('debate.setup.suggestion_3'),
]);

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
        {
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="flex-1 overflow-y-auto">
        <div class="mx-auto w-full max-w-2xl px-6 py-10">
            <div class="mb-7 text-center">
                <span
                    class="mx-auto mb-3 flex size-12 items-center justify-center rounded-2xl bg-accent-blue/10"
                >
                    <DebateIcon class="size-6 text-accent-blue" />
                </span>
                <h1 class="text-xl font-semibold text-ink">
                    {{ t('debate.setup.heading') }}
                </h1>
                <p class="mt-1 text-sm text-ink-muted">
                    {{ t('debate.setup.subheading') }}
                </p>
            </div>

            <!-- Topic -->
            <div
                class="rounded-2xl border border-medium bg-surface p-3 shadow-sm transition-all focus-within:border-strong"
            >
                <textarea
                    v-model="topic"
                    :placeholder="t('debate.setup.topic_placeholder')"
                    rows="3"
                    class="block max-h-60 w-full resize-none bg-transparent px-2 py-1.5 text-[15px] leading-6 text-ink placeholder:text-ink-subtle focus:outline-none"
                />
            </div>

            <!-- Suggestions -->
            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    v-for="(s, i) in suggestions"
                    :key="i"
                    type="button"
                    class="rounded-full border border-soft bg-surface px-3 py-1.5 text-xs text-ink-muted transition-colors hover:border-strong hover:text-ink"
                    @click="topic = s"
                >
                    {{ s }}
                </button>
            </div>

            <!-- Model picker -->
            <div class="mt-6">
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-sm font-medium text-ink">
                        {{ t('debate.setup.pick_models') }}
                    </p>
                    <span
                        :class="[
                            'text-xs font-medium',
                            selected.length >= 2
                                ? 'text-emerald-500'
                                : 'text-ink-subtle',
                        ]"
                    >
                        {{
                            t('debate.setup.selected_count', {
                                count: selected.length,
                            })
                        }}
                    </span>
                </div>
                <!-- Agents first: each debates as that agent (its model/prompt/KBs/tools). -->
                <div v-if="agents.length" class="mb-3">
                    <p
                        class="mb-1.5 text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                    >
                        {{ t('debate.setup.agents') }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="a in agents"
                            :key="a.id"
                            type="button"
                            :disabled="
                                !selected.includes(`agent:${a.id}`) &&
                                selected.length >= MAX_PARTICIPANTS
                            "
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-40',
                                selected.includes(`agent:${a.id}`)
                                    ? 'border-accent-blue bg-accent-blue/10 text-ink'
                                    : 'border-medium bg-surface text-ink-muted hover:border-strong hover:text-ink',
                            ]"
                            @click="toggle(`agent:${a.id}`)"
                        >
                            <span
                                :class="[
                                    'flex size-4 items-center justify-center rounded-full border',
                                    selected.includes(`agent:${a.id}`)
                                        ? 'border-accent-blue bg-accent-blue text-white'
                                        : 'border-medium',
                                ]"
                            >
                                <Check
                                    v-if="selected.includes(`agent:${a.id}`)"
                                    class="size-3"
                                />
                            </span>
                            <Bot class="size-3.5 text-accent-blue" />
                            {{ a.name }}
                        </button>
                    </div>
                </div>

                <p
                    v-if="!models.length"
                    class="rounded-xl border border-dashed border-medium px-3 py-4 text-center text-xs text-ink-subtle"
                >
                    {{ t('debate.setup.no_models') }}
                </p>
                <div v-else class="space-y-3">
                    <div v-for="[provider, items] in grouped" :key="provider">
                        <p
                            class="mb-1.5 text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                        >
                            {{ provider }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="m in items"
                                :key="m.value"
                                type="button"
                                :disabled="
                                    !selected.includes(m.value) &&
                                    selected.length >= MAX_PARTICIPANTS
                                "
                                :class="[
                                    'inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-40',
                                    selected.includes(m.value)
                                        ? 'border-accent-blue bg-accent-blue/10 text-ink'
                                        : 'border-medium bg-surface text-ink-muted hover:border-strong hover:text-ink',
                                ]"
                                @click="toggle(m.value)"
                            >
                                <span
                                    :class="[
                                        'flex size-4 items-center justify-center rounded-full border',
                                        selected.includes(m.value)
                                            ? 'border-accent-blue bg-accent-blue text-white'
                                            : 'border-medium',
                                    ]"
                                >
                                    <Check
                                        v-if="selected.includes(m.value)"
                                        class="size-3"
                                    />
                                </span>
                                {{ m.label }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Moderator + rounds -->
            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <div>
                    <p class="mb-1.5 text-sm font-medium text-ink">
                        {{ t('debate.setup.moderator') }}
                    </p>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 rounded-xl border border-medium bg-surface px-3 py-2 text-sm text-ink transition-colors hover:border-strong"
                            >
                                <Sparkles class="size-3.5 text-accent-blue" />
                                <span
                                    class="min-w-0 flex-1 truncate text-left"
                                    >{{ moderatorLabel }}</span
                                >
                                <ChevronDown class="size-3.5 opacity-50" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            class="max-h-72 w-64 overflow-y-auto"
                        >
                            <DropdownMenuRadioGroup
                                :model-value="moderator ?? ''"
                                @update:model-value="
                                    (v) => (moderator = v as string)
                                "
                            >
                                <template
                                    v-for="[provider, items] in grouped"
                                    :key="provider"
                                >
                                    <DropdownMenuLabel
                                        class="text-[10px] tracking-wider text-ink-subtle uppercase"
                                        >{{ provider }}</DropdownMenuLabel
                                    >
                                    <DropdownMenuRadioItem
                                        v-for="m in items"
                                        :key="m.value"
                                        :value="m.value"
                                        >{{ m.label }}</DropdownMenuRadioItem
                                    >
                                    <DropdownMenuSeparator />
                                </template>
                            </DropdownMenuRadioGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <p class="mt-1 text-[11px] text-ink-subtle">
                        {{ t('debate.setup.moderator_hint') }}
                    </p>
                </div>

                <div>
                    <p
                        class="mb-1.5 flex items-center justify-between text-sm font-medium text-ink"
                    >
                        <span>{{ t('debate.setup.rounds') }}</span>
                        <span class="text-accent-blue">{{ rounds }}</span>
                    </p>
                    <input
                        v-model.number="rounds"
                        type="range"
                        min="1"
                        max="5"
                        step="1"
                        class="h-2 w-full cursor-pointer appearance-none rounded-full bg-white/10 accent-accent-blue"
                    />
                    <p class="mt-1 text-[11px] text-ink-subtle">
                        {{ t('debate.setup.rounds_hint') }}
                    </p>
                </div>
            </div>

            <!-- Start -->
            <button
                type="button"
                :disabled="!canStart"
                class="mt-7 flex w-full items-center justify-center gap-2 rounded-xl bg-accent-blue px-4 py-3 text-sm font-semibold text-white shadow-btn-primary transition-all hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-40"
                @click="start"
            >
                <Loader2 v-if="submitting" class="size-4 animate-spin" />
                <DebateIcon v-else class="size-4" />
                {{ t('debate.setup.start') }}
            </button>
        </div>
    </div>
</template>
