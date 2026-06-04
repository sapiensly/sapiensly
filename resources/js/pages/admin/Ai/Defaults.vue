<script setup lang="ts">
import AiTabs from '@/components/admin/AiTabs.vue';
import DriverChip from '@/components/admin/DriverChip.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import ToggleRow from '@/components/admin/ToggleRow.vue';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Slider } from '@/components/ui/slider';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { AlertTriangle, Brain, Sparkles, Zap } from '@/lib/admin/icons';
import type { AiModel, UUID } from '@/lib/admin/types';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    defaults: {
        primaryChatModelId: UUID | null;
        embeddingModelId: UUID | null;
        fallbackChatModelId: UUID | null;
        streaming: boolean;
        temperature: number;
        maxTokens: number;
    };
    chatModels: AiModel[];
    embeddingModels: AiModel[];
}

const props = defineProps<Props>();
const { t } = useI18n();

const form = ref({ ...props.defaults });

function patch(
    payload: Record<string, unknown>,
    rollback?: () => void,
    onSuccess?: () => void,
) {
    router.patch('/admin/ai/defaults', payload, {
        preserveScroll: true,
        preserveState: true,
        only: ['defaults', 'chatModels', 'embeddingModels'],
        onSuccess: () => {
            form.value = { ...props.defaults };
            if (onSuccess) onSuccess();
        },
        onError: () => {
            if (rollback) rollback();
        },
    });
}

// ── Chat model ──────────────────────────────────────────────────────────
function updatePrimaryChat(id: string | null) {
    const prev = form.value.primaryChatModelId;
    form.value.primaryChatModelId = id;
    patch({ primaryChatModelId: id }, () => {
        form.value.primaryChatModelId = prev;
    });
}

function updateFallbackChat(id: string | null) {
    const prev = form.value.fallbackChatModelId;
    form.value.fallbackChatModelId = id;
    patch({ fallbackChatModelId: id }, () => {
        form.value.fallbackChatModelId = prev;
    });
}

// ── Embedding model swap warning ────────────────────────────────────────
const embeddingSwapOpen = ref(false);
const pendingEmbeddingId = ref<string | null>(null);

function updateEmbedding(id: string | null) {
    // First-time set: just save. Subsequent changes warn about reindexing.
    if (!props.defaults.embeddingModelId || id === props.defaults.embeddingModelId) {
        form.value.embeddingModelId = id;
        patch({ embeddingModelId: id }, () => {
            form.value.embeddingModelId = props.defaults.embeddingModelId;
        });
        return;
    }
    pendingEmbeddingId.value = id;
    embeddingSwapOpen.value = true;
}

function confirmEmbeddingSwap() {
    if (!pendingEmbeddingId.value) return;
    const next = pendingEmbeddingId.value;
    form.value.embeddingModelId = next;
    patch({ embeddingModelId: next }, () => {
        form.value.embeddingModelId = props.defaults.embeddingModelId;
    });
    embeddingSwapOpen.value = false;
    pendingEmbeddingId.value = null;
}

function cancelEmbeddingSwap() {
    embeddingSwapOpen.value = false;
    pendingEmbeddingId.value = null;
}

// ── Streaming / temperature / max tokens ───────────────────────────────
function toggleStreaming(next: boolean) {
    const prev = form.value.streaming;
    form.value.streaming = next;
    patch({ streaming: next }, () => {
        form.value.streaming = prev;
    });
}

const temperatureDraft = ref([form.value.temperature]);
function commitTemperature(next: number[]) {
    const rounded = Math.round(next[0] * 100) / 100;
    if (rounded === form.value.temperature) return;
    const prev = form.value.temperature;
    form.value.temperature = rounded;
    patch({ temperature: rounded }, () => {
        form.value.temperature = prev;
        temperatureDraft.value = [prev];
    });
}

function onTemperatureNumber(e: Event) {
    const v = Number((e.target as HTMLInputElement).value);
    if (!Number.isFinite(v)) return;
    const clamped = Math.min(2, Math.max(0, v));
    temperatureDraft.value = [clamped];
    commitTemperature([clamped]);
}

const maxTokensDraft = ref(form.value.maxTokens);
function commitMaxTokens() {
    const raw = Number(maxTokensDraft.value);
    if (!Number.isFinite(raw)) {
        maxTokensDraft.value = form.value.maxTokens;
        return;
    }
    const clamped = Math.min(200000, Math.max(1, Math.round(raw)));
    maxTokensDraft.value = clamped;
    if (clamped === form.value.maxTokens) return;
    const prev = form.value.maxTokens;
    form.value.maxTokens = clamped;
    patch({ maxTokens: clamped }, () => {
        form.value.maxTokens = prev;
        maxTokensDraft.value = prev;
    });
}
</script>

<template>
    <Head :title="t('admin.nav.ai')" />

    <AdminLayout :title="t('admin.nav.ai')">
        <div class="mx-auto max-w-5xl space-y-6">
            <header class="space-y-1">
                <h1 class="text-[22px] font-semibold leading-tight text-ink">
                    {{ t('admin.ai.heading') }}
                </h1>
                <p class="text-xs text-ink-muted">
                    {{ t('admin.ai.defaults.description') }}
                </p>
            </header>

            <AiTabs current="defaults" />

            <!-- Primary & fallback chat -->
            <SettingsCard
                :icon="Sparkles"
                :title="t('admin.ai.defaults.chat_title')"
                :description="t('admin.ai.defaults.chat_description')"
            >
                <div class="space-y-1.5">
                    <Label class="text-xs text-ink-muted">
                        {{ t('admin.ai.defaults.primary_label') }}
                    </Label>
                    <Select
                        :model-value="form.primaryChatModelId ?? ''"
                        @update:model-value="
                            (v) => updatePrimaryChat(v === '' ? null : String(v))
                        "
                    >
                        <SelectTrigger class="h-9 border-medium bg-surface">
                            <SelectValue :placeholder="t('admin.ai.defaults.unset')" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="m in chatModels"
                                :key="m.id"
                                :value="m.id"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <DriverChip :driver="m.driver" size="sm" />
                                    {{ m.name }}
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="space-y-1.5">
                    <Label class="text-xs text-ink-muted">
                        {{ t('admin.ai.defaults.fallback_label') }}
                    </Label>
                    <Select
                        :model-value="form.fallbackChatModelId ?? ''"
                        @update:model-value="
                            (v) => updateFallbackChat(v === '' ? null : String(v))
                        "
                    >
                        <SelectTrigger class="h-9 border-medium bg-surface">
                            <SelectValue :placeholder="t('admin.ai.defaults.unset')" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="m in chatModels"
                                :key="m.id"
                                :value="m.id"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <DriverChip :driver="m.driver" size="sm" />
                                    {{ m.name }}
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <ToggleRow
                    :model-value="form.streaming"
                    :label="t('admin.ai.defaults.streaming_label')"
                    :description="t('admin.ai.defaults.streaming_description')"
                    @update:model-value="toggleStreaming"
                />
            </SettingsCard>

            <!-- Embedding model -->
            <SettingsCard
                :icon="Brain"
                :title="t('admin.ai.defaults.embedding_title')"
                :description="t('admin.ai.defaults.embedding_description')"
                tint="var(--sp-accent-cyan)"
            >
                <div class="space-y-1.5">
                    <Label class="text-xs text-ink-muted">
                        {{ t('admin.ai.defaults.embedding_label') }}
                    </Label>
                    <Select
                        :model-value="form.embeddingModelId ?? ''"
                        @update:model-value="
                            (v) => updateEmbedding(v === '' ? null : String(v))
                        "
                    >
                        <SelectTrigger class="h-9 border-medium bg-surface">
                            <SelectValue :placeholder="t('admin.ai.defaults.unset')" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="m in embeddingModels"
                                :key="m.id"
                                :value="m.id"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <DriverChip :driver="m.driver" size="sm" />
                                    {{ m.name }}
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="props.defaults.embeddingModelId"
                        class="text-xs text-ink-subtle"
                    >
                        {{ t('admin.ai.defaults.embedding_hint') }}
                    </p>
                </div>
            </SettingsCard>

            <!-- Temperature + max tokens -->
            <SettingsCard
                :icon="Zap"
                :title="t('admin.ai.defaults.sampling_title')"
                :description="t('admin.ai.defaults.sampling_description')"
            >
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <Label class="text-xs text-ink-muted">
                            {{ t('admin.ai.defaults.temperature_label') }}
                        </Label>
                        <Input
                            :model-value="form.temperature"
                            type="number"
                            min="0"
                            max="2"
                            step="0.05"
                            class="h-7 w-20 border-medium bg-surface text-right font-mono text-xs"
                            @blur="onTemperatureNumber"
                            @keyup.enter="onTemperatureNumber"
                        />
                    </div>
                    <Slider
                        v-model="temperatureDraft"
                        :max="2"
                        :step="0.05"
                        class="pt-1"
                        @value-commit="commitTemperature"
                    />
                </div>

                <div class="space-y-1.5">
                    <Label for="max-tokens-input" class="text-xs text-ink-muted">
                        {{ t('admin.ai.defaults.max_tokens_label') }}
                    </Label>
                    <Input
                        id="max-tokens-input"
                        v-model="maxTokensDraft"
                        type="number"
                        min="1"
                        max="200000"
                        class="h-9 w-36 border-medium bg-surface font-mono"
                        @blur="commitMaxTokens"
                        @keyup.enter="commitMaxTokens"
                    />
                </div>
            </SettingsCard>
        </div>

        <!-- Embedding swap warning -->
        <AlertDialog v-model:open="embeddingSwapOpen">
            <AlertDialogContent
                class="rounded-sp-sm border-sp-warning/30 bg-navy"
            >
                <AlertDialogHeader>
                    <AlertDialogTitle
                        class="flex items-center gap-2 text-ink"
                    >
                        <AlertTriangle class="size-4 text-sp-warning" />
                        {{ t('admin.ai.defaults.embedding_swap_title') }}
                    </AlertDialogTitle>
                    <AlertDialogDescription class="text-ink-muted">
                        {{ t('admin.ai.defaults.embedding_swap_body') }}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Badge
                    variant="outline"
                    class="border-sp-warning/40 bg-sp-warning/10 font-normal text-sp-warning"
                >
                    {{ t('admin.ai.defaults.embedding_swap_note') }}
                </Badge>
                <AlertDialogFooter>
                    <AlertDialogCancel @click="cancelEmbeddingSwap">
                        {{ t('common.cancel') }}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        class="bg-sp-warning text-white hover:bg-sp-warning/90"
                        @click="confirmEmbeddingSwap"
                    >
                        {{ t('admin.ai.defaults.embedding_swap_confirm') }}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AdminLayout>
</template>
