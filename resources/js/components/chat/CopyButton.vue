<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

// A small, elegant copy-to-clipboard control for chat bubbles. Inherits the
// bubble's text colour (text-current) so it reads right on a coloured user
// bubble or a muted assistant one; flips to a check for ~1.5s on success.
const props = withDefaults(defineProps<{ text: string; size?: number }>(), {
    size: 13,
});

const { t } = useI18n();
const copied = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;

async function copy(): Promise<void> {
    const text = (props.text ?? '').trim();
    if (!text) return;

    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // Fallback for insecure contexts / older browsers.
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
        } catch {
            /* give up silently */
        }
        document.body.removeChild(ta);
    }

    copied.value = true;
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        copied.value = false;
    }, 1500);
}

onUnmounted(() => {
    if (timer) clearTimeout(timer);
});
</script>

<template>
    <button
        type="button"
        @click.stop="copy"
        :title="copied ? t('common.copied') : t('common.copy')"
        :aria-label="t('common.copy')"
        class="inline-flex shrink-0 items-center justify-center rounded-md p-0.5 text-current opacity-50 transition hover:opacity-100"
    >
        <Check v-if="copied" :size="size" class="text-emerald-500" />
        <Copy v-else :size="size" />
    </button>
</template>
