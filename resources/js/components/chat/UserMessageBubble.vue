<script setup lang="ts">
import CopyButton from '@/components/chat/CopyButton.vue';
import type { ChatAgentRef, ChatMessageDto } from '@/types/chatModule';
import { FileText } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    message: ChatMessageDto;
    agents?: ChatAgentRef[];
}>();

// Split a user message into plain runs and @mention runs so the mentioned
// agents' full names render as distinct chips. Matches the longest agent names
// first (names contain spaces), against the known agent roster.
const mentionRegex = computed(() => {
    const names = (props.agents ?? [])
        .map((a) => a.name)
        .filter((n) => n)
        .sort((a, b) => b.length - a.length)
        .map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    if (!names.length) return null;
    return new RegExp(`@(${names.join('|')})`, 'g');
});

function mentionSegments(
    content: string,
): { text: string; mention: boolean }[] {
    const re = mentionRegex.value;
    if (!re) return [{ text: content, mention: false }];
    const out: { text: string; mention: boolean }[] = [];
    let last = 0;
    for (const m of content.matchAll(re)) {
        const start = m.index ?? 0;
        if (start > last) {
            out.push({ text: content.slice(last, start), mention: false });
        }
        out.push({ text: m[0], mention: true });
        last = start + m[0].length;
    }
    if (last < content.length) {
        out.push({ text: content.slice(last), mention: false });
    }
    return out;
}
</script>

<template>
    <div class="flex justify-end">
        <div class="max-w-[75%]">
            <div
                v-if="message.attachments.length"
                class="mb-1.5 flex flex-wrap justify-end gap-2"
            >
                <a
                    v-for="a in message.attachments"
                    :key="a.id"
                    :href="a.url"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-medium bg-surface px-2 py-1 text-xs text-ink transition-colors hover:border-strong"
                >
                    <img
                        v-if="a.mime.startsWith('image/')"
                        :src="a.url"
                        :alt="a.original_name"
                        class="size-9 rounded-lg object-cover"
                    />
                    <FileText v-else class="size-3.5 text-ink-subtle" />
                    <span class="max-w-[160px] truncate">{{
                        a.original_name
                    }}</span>
                </a>
            </div>
            <div
                class="rounded-[1.4rem] bg-accent-blue px-5 py-3 text-[15px] leading-relaxed font-medium text-white shadow-[0_4px_14px_rgba(26,126,240,0.28)]"
            >
                <p class="break-words whitespace-pre-wrap">
                    <template
                        v-for="(seg, si) in mentionSegments(
                            message.content ?? '',
                        )"
                        :key="si"
                    >
                        <span
                            v-if="seg.mention"
                            class="rounded-md bg-white/25 px-1 font-semibold"
                            >{{ seg.text }}</span
                        >
                        <template v-else>{{ seg.text }}</template>
                    </template>
                </p>
            </div>
            <div
                v-if="message.content"
                class="mt-1 flex justify-end text-ink-subtle"
            >
                <CopyButton :text="message.content ?? ''" :size="13" />
            </div>
        </div>
    </div>
</template>
