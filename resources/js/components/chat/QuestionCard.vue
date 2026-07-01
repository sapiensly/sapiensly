<script setup lang="ts">
import type { ChatMessageDto, QuestionPayloadDto } from '@/types/chatModule';
import { Check, CircleHelp, Loader2 } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    message: ChatMessageDto;
    busy?: boolean;
}>();

const emit = defineEmits<{ answer: [text: string] }>();

// This card only renders question messages; message_type guarantees the payload
// is a QuestionPayloadDto (not the action shape sharing the column).
const payload = computed(
    () => props.message.action_payload as QuestionPayloadDto | null,
);
const options = computed(() => payload.value?.options ?? []);
const answered = computed(() => payload.value?.status === 'answered');
const selected = computed(() => payload.value?.selected ?? null);

// Free-text "Other" escape hatch.
const otherOpen = ref(false);
const otherText = ref('');
const otherInput = ref<HTMLTextAreaElement | null>(null);

function choose(label: string) {
    if (answered.value || props.busy) return;
    emit('answer', label);
}

function openOther() {
    if (answered.value || props.busy) return;
    otherOpen.value = true;
    nextTick(() => otherInput.value?.focus());
}

function submitOther() {
    const text = otherText.value.trim();
    if (!text || answered.value || props.busy) return;
    emit('answer', text);
}
</script>

<template>
    <div
        v-if="payload"
        class="overflow-hidden rounded-2xl border border-accent-blue/30 bg-accent-blue/[0.06] shadow-sm"
    >
        <div class="flex items-center gap-2 px-4 pt-3.5">
            <span
                class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue/15 px-2.5 py-0.5 text-[10px] font-bold tracking-wider text-accent-blue uppercase"
            >
                <CircleHelp class="size-3" />
                {{ t('chat.question.label') }}
            </span>
        </div>

        <div class="px-4 pt-2.5 pb-4">
            <h3 class="text-[15px] font-semibold text-ink">
                {{ payload.question }}
            </h3>

            <!-- Options -->
            <div class="mt-3.5 flex flex-col gap-2">
                <button
                    v-for="option in options"
                    :key="option.label"
                    type="button"
                    :disabled="busy || answered"
                    class="group flex w-full items-start gap-2.5 rounded-xl border px-3.5 py-2.5 text-left transition-colors disabled:cursor-default"
                    :class="
                        answered && selected === option.label
                            ? 'border-accent-blue bg-accent-blue/12'
                            : answered
                              ? 'border-medium bg-surface opacity-50'
                              : 'border-medium bg-surface hover:border-accent-blue/60 hover:bg-accent-blue/[0.08]'
                    "
                    @click="choose(option.label)"
                >
                    <span
                        class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border transition-colors"
                        :class="
                            answered && selected === option.label
                                ? 'border-accent-blue bg-accent-blue text-white'
                                : 'border-medium text-transparent group-hover:border-accent-blue'
                        "
                    >
                        <Check class="size-3" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[13.5px] font-medium text-ink">
                            {{ option.label }}
                        </span>
                        <span
                            v-if="option.description"
                            class="mt-0.5 block text-[12px] leading-snug text-ink-muted"
                        >
                            {{ option.description }}
                        </span>
                    </span>
                </button>

                <!-- Other: free-text escape hatch -->
                <template v-if="payload.allow_other && !answered">
                    <button
                        v-if="!otherOpen"
                        type="button"
                        :disabled="busy"
                        class="flex w-full items-center gap-2.5 rounded-xl border border-dashed border-medium bg-surface px-3.5 py-2.5 text-left text-[13.5px] text-ink-subtle transition-colors hover:border-accent-blue/60 hover:text-ink disabled:cursor-default"
                        @click="openOther"
                    >
                        {{ t('chat.question.other') }}
                    </button>
                    <div
                        v-else
                        class="rounded-xl border border-accent-blue/40 bg-surface p-2.5"
                    >
                        <textarea
                            ref="otherInput"
                            v-model="otherText"
                            rows="2"
                            :placeholder="t('chat.question.other_placeholder')"
                            class="w-full resize-none bg-transparent text-[13.5px] text-ink outline-none placeholder:text-ink-subtle"
                            @keydown.enter.exact.prevent="submitOther"
                        />
                        <div class="mt-1.5 flex justify-end">
                            <button
                                type="button"
                                :disabled="busy || !otherText.trim()"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-accent-blue px-3 py-1.5 text-[12.5px] font-semibold text-white transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
                                @click="submitOther"
                            >
                                <Loader2
                                    v-if="busy"
                                    class="size-3.5 animate-spin"
                                />
                                {{ t('chat.question.send') }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Locked: free-text answer that wasn't one of the listed options. -->
            <p
                v-if="
                    answered &&
                    selected &&
                    !options.some((o) => o.label === selected)
                "
                class="mt-3 rounded-xl border border-accent-blue bg-accent-blue/12 px-3.5 py-2.5 text-[13.5px] text-ink"
            >
                {{ selected }}
            </p>
        </div>
    </div>
</template>
