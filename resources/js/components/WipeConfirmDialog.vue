<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AlertTriangle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

export interface WipeCounts {
    knowledge_bases: number;
    documents: number;
    chunks: number;
    organizations: number;
}

interface Props {
    open: boolean;
    counts: WipeCounts | null;
    /**
     * When true, phrase the modal for the global scope (affects multiple
     * tenants). When false, phrase it for a single workspace.
     */
    isGlobalScope?: boolean;
    processing?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    isGlobalScope: false,
    processing: false,
});

const emit = defineEmits<{
    (e: 'confirm'): void;
    (e: 'cancel'): void;
    (e: 'update:open', value: boolean): void;
}>();

const { t } = useI18n();

const confirmInput = ref('');

watch(
    () => props.open,
    (open) => {
        if (open) confirmInput.value = '';
    },
);

const canConfirm = computed<boolean>(() => confirmInput.value === 'DELETE');

const onConfirm = () => {
    if (canConfirm.value) emit('confirm');
};

const close = () => {
    emit('update:open', false);
    emit('cancel');
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <div
                    class="mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400"
                >
                    <AlertTriangle class="h-6 w-6" />
                </div>
                <DialogTitle>{{ t('wipe_dialog.title') }}</DialogTitle>
                <DialogDescription>
                    {{
                        isGlobalScope
                            ? t('wipe_dialog.description_global')
                            : t('wipe_dialog.description_tenant')
                    }}
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="counts"
                class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
            >
                <p class="mb-2 font-medium">{{ t('wipe_dialog.counts_heading') }}</p>
                <ul class="space-y-1 text-xs">
                    <li>
                        <strong>{{ counts.knowledge_bases }}</strong>
                        {{ t('wipe_dialog.counts.knowledge_bases') }}
                    </li>
                    <li>
                        <strong>{{ counts.documents }}</strong>
                        {{ t('wipe_dialog.counts.documents') }}
                    </li>
                    <li>
                        <strong>{{ counts.chunks }}</strong>
                        {{ t('wipe_dialog.counts.chunks') }}
                    </li>
                    <li v-if="isGlobalScope">
                        <strong>{{ counts.organizations }}</strong>
                        {{ t('wipe_dialog.counts.organizations') }}
                    </li>
                </ul>
            </div>

            <div class="space-y-2">
                <Label for="wipe_confirm_input">
                    {{ t('wipe_dialog.type_to_confirm') }}
                </Label>
                <Input
                    id="wipe_confirm_input"
                    v-model="confirmInput"
                    autocomplete="off"
                    placeholder="DELETE"
                />
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" @click="close">
                    {{ t('wipe_dialog.cancel') }}
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="!canConfirm || processing"
                    @click="onConfirm"
                >
                    {{ processing ? t('wipe_dialog.processing') : t('wipe_dialog.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
