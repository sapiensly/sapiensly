<script setup lang="ts">
import * as BotFlowController from '@/actions/App/Http/Controllers/BotFlowController';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import type { BotFlowDefinition } from '@/types/botFlows';
import axios from 'axios';
import { ChevronDown, Loader2, Sparkles } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    chatbotId: string;
}>();

const emit = defineEmits<{
    generated: [definition: BotFlowDefinition];
}>();

const description = ref('');
const loading = ref(false);
const error = ref<string | null>(null);
const collapsed = ref(false);

const generate = async () => {
    if (!description.value.trim() || loading.value) {
        return;
    }
    loading.value = true;
    error.value = null;

    try {
        const { data } = await axios.post(
            BotFlowController.scaffold({ chatbot: props.chatbotId }).url,
            { description: description.value.trim() },
        );
        emit('generated', data.definition as BotFlowDefinition);
        collapsed.value = true;
    } catch {
        error.value = t('flows.assistant.error');
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <div
        class="pointer-events-auto absolute top-3 left-1/2 z-10 w-[420px] max-w-[calc(100%-2rem)] -translate-x-1/2 rounded-sp-md border border-soft bg-navy/95 shadow-sp-float backdrop-blur"
    >
        <button
            type="button"
            class="flex w-full items-center gap-2 px-3.5 py-2.5 text-left"
            @click="collapsed = !collapsed"
        >
            <span
                class="flex size-6 shrink-0 items-center justify-center rounded-xs"
                style="background-color: color-mix(in oklab, #a855f7 18%, transparent); color: #a855f7"
            >
                <Sparkles class="size-3.5" />
            </span>
            <span class="flex-1 text-[13px] font-medium text-ink">
                {{ t('flows.assistant.title') }}
            </span>
            <ChevronDown
                class="size-4 text-ink-subtle transition-transform"
                :class="collapsed ? '-rotate-90' : ''"
            />
        </button>

        <div v-if="!collapsed" class="space-y-2.5 px-3.5 pt-0.5 pb-3.5">
            <p class="text-[11px] leading-snug text-ink-subtle">
                {{ t('flows.assistant.hint') }}
            </p>
            <Textarea
                v-model="description"
                :rows="3"
                :placeholder="t('flows.assistant.placeholder')"
                :disabled="loading"
                @keydown.meta.enter="generate"
                @keydown.ctrl.enter="generate"
            />
            <p v-if="error" class="text-[11px] text-sp-danger">{{ error }}</p>
            <div class="flex items-center justify-between gap-2">
                <span class="text-[10px] text-ink-subtle">
                    {{ t('flows.assistant.replace_warning') }}
                </span>
                <Button
                    size="sm"
                    :disabled="loading || !description.trim()"
                    @click="generate"
                >
                    <Loader2 v-if="loading" class="mr-1.5 size-3.5 animate-spin" />
                    <Sparkles v-else class="mr-1.5 size-3.5" />
                    {{ t('flows.assistant.generate') }}
                </Button>
            </div>
        </div>
    </div>
</template>
