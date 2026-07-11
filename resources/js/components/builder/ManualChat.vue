<script setup lang="ts">
/**
 * Specialized add-chart chat for manual adjust: the user describes a chart
 * in their words and the DETERMINISTIC mini-Express (intent vocabulary +
 * lexicon over the board's own objects) derives, dedupes and inserts it —
 * zero model calls, honest refusals when the ask doesn't anchor.
 */
import axios from 'axios';
import { Send, Sparkles } from '@lucide/vue';
import { nextTick, ref } from 'vue';

const props = defineProps<{ appId: string; pageSlug?: string }>();
const emit = defineEmits<{ (e: 'added', blockId: string): void }>();

const messages = ref<{ role: 'user' | 'assistant'; text: string }[]>([
    {
        role: 'assistant',
        text: 'Dime qué gráfica agregar — nombra la dimensión y, si quieres, la forma. Ej.: «pareto de motivos», «top de backlog por categoría», «tendencia semanal de reabiertos».',
    },
]);
const input = ref('');
const busy = ref(false);
const listEl = ref<HTMLElement | null>(null);

async function send() {
    const prompt = input.value.trim();
    if (prompt === '' || busy.value) return;
    input.value = '';
    messages.value.push({ role: 'user', text: prompt });
    busy.value = true;
    try {
        const { data } = await axios.post(
            `/apps/${props.appId}/builder/charts`,
            { prompt, page_slug: props.pageSlug ?? null },
        );
        messages.value.push({ role: 'assistant', text: data.message });
        if (data.block_id) emit('added', data.block_id);
    } catch (e: unknown) {
        const msg =
            (e as { response?: { data?: { message?: string } } }).response
                ?.data?.message ?? 'No pude derivar esa gráfica.';
        messages.value.push({ role: 'assistant', text: msg });
    } finally {
        busy.value = false;
        await nextTick();
        listEl.value?.scrollTo({ top: listEl.value.scrollHeight });
    }
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div
            class="flex items-center gap-2 border-b border-soft px-4 py-3 text-xs font-medium text-ink-muted"
        >
            <Sparkles class="size-3.5 text-accent-blue" />
            Agregar gráfica
        </div>
        <div ref="listEl" class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
            <div
                v-for="(m, i) in messages"
                :key="i"
                :class="[
                    'max-w-[85%] rounded-sp-sm px-3 py-2 text-xs leading-relaxed',
                    m.role === 'user'
                        ? 'ml-auto bg-accent-blue/15 text-ink'
                        : 'bg-surface text-ink-muted',
                ]"
            >
                {{ m.text }}
            </div>
        </div>
        <form
            class="flex items-center gap-2 border-t border-soft p-3"
            @submit.prevent="send"
        >
            <input
                v-model="input"
                type="text"
                :disabled="busy"
                placeholder="pareto de motivos, tendencia de backlog…"
                class="min-w-0 flex-1 rounded-pill border border-medium bg-surface px-3.5 py-2 text-sm text-ink"
            />
            <button
                type="submit"
                :disabled="busy || input.trim() === ''"
                class="rounded-pill bg-accent-blue p-2 text-white transition-opacity disabled:opacity-40"
            >
                <Send class="size-4" />
            </button>
        </form>
    </div>
</template>
