<script setup lang="ts">
/**
 * The runtime's own "are you sure".
 *
 * Mounted once by the renderer; every call site reaches it through
 * {@see confirmAction}. See `confirm.ts` for why the browser's own dialog was
 * not good enough for the one control in a generated app that destroys data.
 *
 * Escape and the backdrop both answer NO — a confirmation you can dismiss by
 * accident must dismiss into the safe answer.
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { answerConfirm, pendingConfirm } from './confirm';
import { themeTokens, useRuntimeTheme } from './useRuntimeTheme';
import { runtimeWord } from './words';

const t = themeTokens(useRuntimeTheme());
const acceptEl = ref<HTMLButtonElement | null>(null);

const open = computed(() => pendingConfirm.value !== null);

function word(key: string): string {
    return runtimeWord(pendingConfirm.value?.locale, key);
}

function onKeydown(event: KeyboardEvent): void {
    if (open.value && event.key === 'Escape') answerConfirm(false);
}

// Focus lands on the SAFE choice, so a stray Enter cancels rather than
// destroys.
const cancelEl = ref<HTMLButtonElement | null>(null);
watch(open, async (isOpen) => {
    if (!isOpen) return;
    await new Promise((r) => setTimeout(r, 0));
    cancelEl.value?.focus();
});

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="pendingConfirm"
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            role="alertdialog"
            aria-modal="true"
        >
            <div
                class="absolute inset-0 bg-black/50"
                @click="answerConfirm(false)"
            />

            <div
                :class="[
                    'relative w-full max-w-sm rounded-lg border p-5 shadow-xl',
                    t.surface,
                    t.text,
                ]"
            >
                <h2 v-if="pendingConfirm.title" class="text-sm font-semibold">
                    {{ pendingConfirm.title }}
                </h2>
                <p class="mt-1.5 text-sm leading-relaxed opacity-80">
                    {{ pendingConfirm.message }}
                </p>

                <div class="mt-5 flex justify-end gap-2">
                    <button
                        ref="cancelEl"
                        type="button"
                        data-sp-confirm="no"
                        :class="[
                            'rounded-md border px-3 py-1.5 text-sm',
                            t.surfaceMuted,
                        ]"
                        @click="answerConfirm(false)"
                    >
                        {{ word('cancel') }}
                    </button>
                    <button
                        ref="acceptEl"
                        type="button"
                        data-sp-confirm="yes"
                        class="rounded-md px-3 py-1.5 text-sm font-medium text-white"
                        :class="
                            pendingConfirm.danger
                                ? 'bg-red-600 hover:bg-red-500'
                                : 'bg-accent-blue hover:bg-accent-blue-hover'
                        "
                        @click="answerConfirm(true)"
                    >
                        {{ word(pendingConfirm.danger ? 'delete' : 'confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
