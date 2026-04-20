<script setup lang="ts">
import type { MessageNodeConfig } from '@/types/flows';
import { Handle, Position, useVueFlow } from '@vue-flow/core';
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    id: string;
    data: MessageNodeConfig;
}>();

const { updateNodeData } = useVueFlow();

const isEditing = ref(false);
const draft = ref('');
const textarea = ref<HTMLTextAreaElement | null>(null);

async function startEdit(event: Event) {
    event.stopPropagation();
    draft.value = props.data.message ?? '';
    isEditing.value = true;
    await nextTick();
    textarea.value?.focus();
    textarea.value?.select();
}

function commit() {
    if (!isEditing.value) return;
    updateNodeData(props.id, { message: draft.value });
    isEditing.value = false;
}

function cancel() {
    isEditing.value = false;
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        event.preventDefault();
        cancel();
    } else if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        commit();
    }
}
</script>

<template>
    <div class="min-w-[220px] max-w-[280px] rounded-sp-sm border border-soft bg-navy p-3 shadow-sp-float">
        <Handle type="target" :position="Position.Top" class="!bg-accent-blue" />

        <div class="mb-1 text-xs font-medium text-ink-muted">
            {{ t('flows.nodes.message') }}
        </div>

        <textarea
            v-if="isEditing"
            ref="textarea"
            v-model="draft"
            class="nodrag w-full resize-none rounded-xs border border-medium bg-white/5 p-2 text-sm text-ink placeholder:text-ink-subtle focus:border-accent-blue focus:outline-none focus:ring-1 focus:ring-accent-blue"
            rows="3"
            :placeholder="t('flows.nodes.no_message')"
            @blur="commit"
            @keydown="onKeydown"
            @click.stop
        />
        <div
            v-else
            class="cursor-text whitespace-pre-wrap rounded-xs px-1 py-0.5 text-sm text-ink hover:bg-white/5"
            :class="{ 'italic text-ink-subtle': !data.message }"
            @dblclick="startEdit"
        >
            {{ data.message || t('flows.nodes.no_message') }}
        </div>

        <Handle type="source" :position="Position.Bottom" class="!bg-accent-blue" />
    </div>
</template>
