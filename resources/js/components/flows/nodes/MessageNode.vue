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
    <div class="min-w-[220px] max-w-[280px] rounded-lg border bg-card p-3 shadow-sm">
        <Handle type="target" :position="Position.Top" class="!bg-primary" />

        <div class="mb-1 text-xs font-medium text-muted-foreground">
            {{ t('flows.nodes.message') }}
        </div>

        <textarea
            v-if="isEditing"
            ref="textarea"
            v-model="draft"
            class="nodrag w-full resize-none rounded border bg-background p-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            rows="3"
            :placeholder="t('flows.nodes.no_message')"
            @blur="commit"
            @keydown="onKeydown"
            @click.stop
        />
        <div
            v-else
            class="cursor-text whitespace-pre-wrap rounded px-1 py-0.5 text-sm hover:bg-muted/50"
            :class="{ 'italic text-muted-foreground': !data.message }"
            @dblclick="startEdit"
        >
            {{ data.message || t('flows.nodes.no_message') }}
        </div>

        <Handle type="source" :position="Position.Bottom" class="!bg-primary" />
    </div>
</template>
