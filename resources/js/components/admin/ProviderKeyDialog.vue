<script setup lang="ts">
import DriverChip from '@/components/admin/DriverChip.vue';
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
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    driver: AiDriver | null;
    label: string | null;
    credentialFields: string[];
    mode: 'add' | 'rotate';
}

const props = defineProps<Props>();

const open = defineModel<boolean>('open', { required: true });
const { t } = useI18n();

const form = useForm<{ driver: string; credentials: Record<string, string> }>({
    driver: '',
    credentials: { api_key: '', url: '' },
});

// Keep the hidden driver field in sync with the row the user opened.
watch(
    () => props.driver,
    (driver) => {
        form.driver = driver ?? '';
    },
    { immediate: true },
);

const showUrl = computed(() => props.credentialFields.includes('url'));

const credentialLabel = (field: string): string =>
    field === 'url'
        ? t('admin.ai.providers.base_url_label')
        : t('admin.ai.providers.api_key_label');

function submit() {
    if (!props.driver) return;
    const credentials: Record<string, string> = {
        api_key: form.credentials.api_key,
    };
    if (showUrl.value && form.credentials.url) {
        credentials.url = form.credentials.url;
    }
    form
        .transform(() => ({ driver: props.driver, credentials }))
        .post('/admin/ai/providers/key', {
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
        <DialogContent class="rounded-sp-sm border-medium bg-navy sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2 text-ink">
                    <Key class="size-4 text-accent-blue" />
                    {{
                        mode === 'add'
                            ? t('admin.ai.providers.add_title')
                            : t('admin.ai.providers.rotate_title')
                    }}
                </DialogTitle>
                <DialogDescription class="text-ink-muted">
                    {{
                        mode === 'add'
                            ? t('admin.ai.providers.add_description')
                            : t('admin.ai.providers.rotate_description')
                    }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="driver" class="flex items-center gap-2 text-xs">
                <DriverChip :driver="driver" size="sm" />
                <span class="text-ink-muted">{{ label }}</span>
            </div>

            <form class="space-y-3" @submit.prevent="submit">
                <div class="space-y-1.5">
                    <Label for="provider-key-input" class="text-xs">
                        {{ credentialLabel('api_key') }}
                    </Label>
                    <Input
                        id="provider-key-input"
                        v-model="form.credentials.api_key"
                        type="password"
                        autocomplete="off"
                        :placeholder="t('admin.ai.providers.api_key_placeholder')"
                        class="font-mono"
                    />
                    <p
                        v-if="(form.errors as Record<string, string>)['credentials.api_key']"
                        class="text-xs text-sp-danger"
                    >
                        {{ (form.errors as Record<string, string>)['credentials.api_key'] }}
                    </p>
                </div>

                <div v-if="showUrl" class="space-y-1.5">
                    <Label for="provider-url-input" class="text-xs">
                        {{ credentialLabel('url') }}
                    </Label>
                    <Input
                        id="provider-url-input"
                        v-model="form.credentials.url"
                        type="text"
                        autocomplete="off"
                        :placeholder="t('admin.ai.providers.base_url_placeholder')"
                        class="font-mono"
                    />
                    <p
                        v-if="(form.errors as Record<string, string>)['credentials.url']"
                        class="text-xs text-sp-danger"
                    >
                        {{ (form.errors as Record<string, string>)['credentials.url'] }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="cancel">
                        {{ t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || form.credentials.api_key.length < 16"
                        class="rounded-pill bg-accent-blue text-white shadow-btn-primary hover:bg-accent-blue-hover"
                    >
                        {{ t('admin.ai.providers.save_cta') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
