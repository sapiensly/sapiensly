<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { computed, onMounted, onUnmounted, provide, ref } from 'vue';
import AppRenderer from '../AppRenderer.vue';
import type { AnyBlock, BlockData, ObjectDef } from '../types/manifest';
import { modalBus } from '../useActionExecutor';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface ModalBlock {
    id: string;
    type: 'modal';
    title?: string;
    description?: string;
    size?: 'sm' | 'md' | 'lg';
    blocks: AnyBlock[];
}

const props = defineProps<{
    block: ModalBlock;
    blockData: BlockData;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const open = ref(false);
/**
 * Params delivered alongside the `open` event. Inside the modal tree these
 * are exposed via `provide('modalParams', …)` so a nested BlockForm can
 * read them at submit time — that's how an Edit modal opened from a table
 * action column knows which record_id to send back to the server.
 */
const modalParams = ref<Record<string, unknown>>({});
provide('modalParams', modalParams);
// Flag for children (BlockForm, BlockMultiStepForm) so they drop their own
// card chrome — the modal shell already provides border + padding, and the
// double-card looks weird (a "card inside a card" with a white frame around).
provide('insideModal', true);

let offOpen: () => void = () => {};
let offClose: () => void = () => {};

onMounted(() => {
    offOpen = modalBus.on('open', (id, params) => {
        if (id === props.block.id) {
            modalParams.value = params ?? {};
            open.value = true;
        }
    });
    offClose = modalBus.on('close', (id) => {
        if (id === undefined || id === props.block.id) open.value = false;
    });
});

onUnmounted(() => {
    offOpen();
    offClose();
});

const widthClass = computed(() => {
    switch (props.block.size ?? 'md') {
        case 'sm':
            return 'sm:max-w-md';
        case 'lg':
            return 'sm:max-w-3xl';
        default:
            return 'sm:max-w-xl';
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <!-- Force the runtime theme onto the DialogContent so a dark app -->
        <!-- doesn't show a white shell around the dark form inside.      -->
        <DialogContent :class="[widthClass, t.surface, t.text]">
            <DialogHeader v-if="block.title">
                <DialogTitle>{{ block.title }}</DialogTitle>
                <!-- Visible when the manifest provides a description, otherwise -->
                <!-- a screen-reader-only fallback so the underlying Radix       -->
                <!-- Dialog isn't missing its aria-describedby. Silences the    -->
                <!-- "Missing Description" console warning without polluting UI. -->
                <DialogDescription v-if="block.description">{{
                    block.description
                }}</DialogDescription>
                <DialogDescription v-else class="sr-only"
                    >{{ block.title }} dialog</DialogDescription
                >
            </DialogHeader>
            <DialogDescription v-else class="sr-only">Dialog</DialogDescription>
            <div class="space-y-4">
                <AppRenderer
                    :blocks="block.blocks"
                    :block-data="blockData"
                    :objects="objects"
                    :locale="locale"
                    :default-currency="defaultCurrency"
                    :nested="true"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
