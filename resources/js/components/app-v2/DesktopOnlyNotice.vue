<script setup lang="ts">
/**
 * Says out loud what the canvas builders assume: they are desktop tools.
 *
 * The app builder, deck builder and flow editors are multi-pane surfaces with
 * drag-and-drop canvases. Unlike the rest of the product they were exempted
 * from the responsive pass by decision, not oversight — squeezing a three-pane
 * editor into 390px produces something worse than an honest notice. This is
 * that notice: visible only below `lg`, where the room runs out.
 *
 * It does not block the editor. Someone who wants to look around, or make a
 * small text edit on a tablet, still can.
 *
 * `messageKey` lets a caller scope the notice. The app builder is responsive
 * now, so it no longer shows this page-wide — only over its fine-tune canvas,
 * with copy that names that mode instead of condemning the whole editor.
 */
import { MonitorSmartphone } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

withDefaults(defineProps<{ messageKey?: string }>(), {
    messageKey: 'builder.desktop_only',
});

const { t } = useI18n();
</script>

<template>
    <div
        class="flex items-start gap-2.5 border-b border-soft bg-surface px-4 py-2.5 lg:hidden"
        role="status"
    >
        <MonitorSmartphone class="mt-px size-4 shrink-0 text-ink-muted" />
        <p class="text-xs leading-relaxed text-ink-muted">
            {{ t(messageKey) }}
        </p>
    </div>
</template>
