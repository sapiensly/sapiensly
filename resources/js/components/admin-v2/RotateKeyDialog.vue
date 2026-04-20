<script setup lang="ts">
import DriverChip from '@/components/admin-v2/DriverChip.vue';
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
import { Key } from '@/lib/admin/icons';
import type { AiDriver } from '@/lib/admin/types';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

interface Props {
    providerId: string | null;
    driver: AiDriver | null;
    label: string | null;
}

const props = defineProps<Props>();

const open = defineModel<boolean>('open', { required: true });
const { t } = useI18n();

const form = useForm({ api_key: '' });

function submit() {
    if (!props.providerId) return;
    form.post(`/admin2/ai/providers/${props.providerId}/rotate-key`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}

function cancel() {
    open.value = false;
    form.reset();
    form.clearErrors();
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="rounded-sp-sm border-medium bg-navy sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2 text-ink">
                    <Key class="size-4 text-accent-blue" />
                    {{ t('admin_v2.ai.rotate.title') }}
                </DialogTitle>
                <DialogDescription class="text-ink-muted">
                    {{ t('admin_v2.ai.rotate.description') }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="driver" class="flex items-center gap-2 text-xs">
                <DriverChip :driver="driver" size="sm" />
                <span class="text-ink-muted">{{ label }}</span>
            </div>

            <form class="space-y-3" @submit.prevent="submit">
                <div class="space-y-1.5">
                    <Label for="rotate-key-input" class="text-xs">
                        {{ t('admin_v2.ai.rotate.new_key_label') }}
                    </Label>
                    <Input
                        id="rotate-key-input"
                        v-model="form.api_key"
                        type="password"
                        autocomplete="off"
                        :placeholder="t('admin_v2.ai.rotate.new_key_placeholder')"
                        class="font-mono"
                    />
                    <p
                        v-if="form.errors.api_key"
                        class="text-xs text-sp-danger"
                    >
                        {{ form.errors.api_key }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="cancel">
                        {{ t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || form.api_key.length < 16"
                        class="rounded-pill bg-accent-blue text-white shadow-btn-primary hover:bg-accent-blue-hover"
                    >
                        {{ t('admin_v2.ai.rotate.submit') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
