<script setup lang="ts">
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import axios from 'axios';
import { FileImage, Link2 as LinkIcon, Loader2, Upload, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    appId: string;
    conversationId: string;
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'imported', payload: { messages: unknown[]; latest_message_id: string }): void;
}>();

const { t } = useI18n();

type SourceTab = 'image' | 'url' | 'html';
const tab = ref<SourceTab>('image');
const businessContext = ref('');
const url = ref('');
const html = ref('');
const file = ref<File | null>(null);
const filePreviewUrl = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const submitting = ref(false);
const errorText = ref<string | null>(null);

// Reset every time the dialog re-opens so a previous attempt doesn't leak
// into the next one. Watching `open` directly so we react both on open AND
// close without an explicit close handler.
watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            // On close, drop the blob URL we may have created for the preview.
            if (filePreviewUrl.value) {
                URL.revokeObjectURL(filePreviewUrl.value);
                filePreviewUrl.value = null;
            }
            file.value = null;
            url.value = '';
            html.value = '';
            businessContext.value = '';
            errorText.value = null;
            tab.value = 'image';
        }
    },
);

const ACCEPTED_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
const MAX_BYTES = 5 * 1024 * 1024;

function pickFile() {
    fileInput.value?.click();
}

function onFileChange(event: Event) {
    const target = event.target as HTMLInputElement;
    const picked = target.files?.[0] ?? null;
    if (picked) {
        acceptFile(picked);
    }
    target.value = '';
}

function acceptFile(picked: File) {
    if (!ACCEPTED_MIMES.includes(picked.type)) {
        errorText.value = t('apps.builder.attachment_invalid_type');
        return;
    }
    if (picked.size > MAX_BYTES) {
        errorText.value = t('apps.builder.attachment_too_large');
        return;
    }
    errorText.value = null;
    if (filePreviewUrl.value) {
        URL.revokeObjectURL(filePreviewUrl.value);
    }
    file.value = picked;
    filePreviewUrl.value = URL.createObjectURL(picked);
}

function clearFile() {
    if (filePreviewUrl.value) {
        URL.revokeObjectURL(filePreviewUrl.value);
        filePreviewUrl.value = null;
    }
    file.value = null;
}

// Pull file out of a drop event on the image tab pane. Mirrors the chat
// composer's drag handler so the affordance feels consistent.
function onDrop(event: DragEvent) {
    event.preventDefault();
    const dropped = event.dataTransfer?.files?.[0];
    if (dropped) acceptFile(dropped);
}

const submitDisabled = computed(() => {
    if (submitting.value) return true;
    if (tab.value === 'image') return file.value === null;
    if (tab.value === 'url') return url.value.trim() === '';
    if (tab.value === 'html') return html.value.trim() === '';
    return true;
});

async function submit() {
    if (submitDisabled.value) {
        errorText.value = t('apps.builder.wireframe.no_source');
        return;
    }
    submitting.value = true;
    errorText.value = null;

    const form = new FormData();
    form.append('conversation_id', props.conversationId);
    form.append('source', tab.value);
    if (businessContext.value.trim() !== '') {
        form.append('business_context', businessContext.value.trim());
    }
    if (tab.value === 'image' && file.value) {
        form.append('image', file.value);
    } else if (tab.value === 'url') {
        form.append('url', url.value.trim());
    } else if (tab.value === 'html') {
        form.append('html', html.value);
    }

    try {
        const { data } = await axios.post(
            `/apps/${props.appId}/builder/wireframe-import`,
            form,
            {
                headers: { 'Content-Type': 'multipart/form-data' },
                // URL scrape + image download can take a moment; generous
                // ceiling so the modal doesn't bail before the backend
                // finishes assembling the message.
                timeout: 45_000,
            },
        );
        emit('imported', data);
        emit('update:open', false);
    } catch (e) {
        const err = e as {
            message?: string;
            response?: { status?: number; data?: { message?: string; error?: string } };
        };
        const status = err.response?.status;
        const body = err.response?.data?.message ?? err.response?.data?.error;
        errorText.value = status ? `HTTP ${status}${body ? ' — ' + body : ''}` : (err.message ?? 'Network error');
        // eslint-disable-next-line no-console
        console.error('Wireframe import failed:', e);
    } finally {
        submitting.value = false;
    }
}

const tabs: { id: SourceTab; labelKey: string; icon: typeof Upload }[] = [
    { id: 'image', labelKey: 'apps.builder.wireframe.tab_image', icon: FileImage },
    { id: 'url', labelKey: 'apps.builder.wireframe.tab_url', icon: LinkIcon },
    { id: 'html', labelKey: 'apps.builder.wireframe.tab_html', icon: Upload },
];
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{ t('apps.builder.wireframe.title') }}</DialogTitle>
                <DialogDescription>{{ t('apps.builder.wireframe.description') }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <!-- Tabs row: only one source is sent. -->
                <div class="flex gap-1 rounded-md bg-surface p-1">
                    <button
                        v-for="opt in tabs"
                        :key="opt.id"
                        type="button"
                        @click="tab = opt.id"
                        :class="[
                            'inline-flex flex-1 items-center justify-center gap-1.5 rounded-pill px-3 py-1.5 text-xs transition-colors',
                            tab === opt.id
                                ? 'bg-accent-blue/15 text-accent-blue'
                                : 'text-ink-muted hover:text-ink',
                        ]"
                    >
                        <component :is="opt.icon" class="size-3.5" />
                        {{ t(opt.labelKey) }}
                    </button>
                </div>

                <!-- IMAGE tab -->
                <div v-if="tab === 'image'" class="space-y-2">
                    <p class="text-xs text-ink-muted">{{ t('apps.builder.wireframe.image_hint') }}</p>
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/png,image/jpeg,image/webp,image/gif"
                        class="hidden"
                        @change="onFileChange"
                    />
                    <div
                        v-if="!file"
                        @dragover.prevent
                        @drop="onDrop"
                        class="flex h-32 flex-col items-center justify-center gap-1 rounded-sp-sm border-2 border-dashed border-soft bg-surface text-xs text-ink-muted"
                    >
                        <Upload class="size-5" />
                        <button
                            type="button"
                            @click="pickFile"
                            class="rounded-pill border border-medium bg-surface px-3 py-1 text-[11px] text-ink transition-colors hover:border-strong"
                        >
                            {{ t('apps.builder.attach_image') }}
                        </button>
                    </div>
                    <div
                        v-else
                        class="flex items-center gap-3 rounded-sp-sm border border-soft bg-surface p-2"
                    >
                        <img
                            :src="filePreviewUrl ?? ''"
                            alt=""
                            class="size-16 rounded object-cover"
                        />
                        <div class="flex-1 truncate text-xs text-ink-muted">{{ file.name }}</div>
                        <button
                            type="button"
                            @click="clearFile"
                            class="rounded-full p-1 text-ink-muted transition-colors hover:bg-surface-hover hover:text-ink"
                            :title="t('apps.builder.remove_attachment')"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>
                </div>

                <!-- URL tab -->
                <div v-else-if="tab === 'url'" class="space-y-2">
                    <p class="text-xs text-ink-muted">{{ t('apps.builder.wireframe.url_hint') }}</p>
                    <input
                        v-model="url"
                        type="url"
                        :placeholder="t('apps.builder.wireframe.url_placeholder')"
                        class="h-9 w-full rounded-md border border-medium bg-surface px-3 text-sm text-ink placeholder:text-ink-subtle"
                    />
                </div>

                <!-- HTML tab -->
                <div v-else class="space-y-2">
                    <p class="text-xs text-ink-muted">{{ t('apps.builder.wireframe.html_hint') }}</p>
                    <textarea
                        v-model="html"
                        :placeholder="t('apps.builder.wireframe.html_placeholder')"
                        rows="6"
                        class="w-full rounded-md border border-medium bg-surface px-3 py-2 font-mono text-[11px] leading-relaxed text-ink placeholder:text-ink-subtle"
                    />
                </div>

                <!-- Business context — shared across all tabs. -->
                <div class="space-y-1.5">
                    <label class="text-xs text-ink-muted">{{ t('apps.builder.wireframe.business_label') }}</label>
                    <textarea
                        v-model="businessContext"
                        :placeholder="t('apps.builder.wireframe.business_placeholder')"
                        rows="2"
                        class="w-full rounded-md border border-medium bg-surface px-3 py-2 text-xs text-ink placeholder:text-ink-subtle"
                    />
                    <p class="text-[11px] text-ink-subtle">{{ t('apps.builder.wireframe.business_hint') }}</p>
                </div>

                <p v-if="errorText" class="text-[11px] text-red-400">{{ errorText }}</p>
            </div>

            <DialogFooter>
                <button
                    type="button"
                    @click="emit('update:open', false)"
                    class="inline-flex items-center rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:bg-surface-hover"
                >
                    {{ t('apps.builder.wireframe.cancel') }}
                </button>
                <button
                    type="button"
                    @click="submit"
                    :disabled="submitDisabled"
                    class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                >
                    <Loader2 v-if="submitting" class="size-3.5 animate-spin" />
                    {{ submitting ? t('apps.builder.wireframe.submitting') : t('apps.builder.wireframe.submit') }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
