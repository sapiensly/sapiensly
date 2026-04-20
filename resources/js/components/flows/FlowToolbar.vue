<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Check, Cloud, CloudOff, Loader2, Power, PowerOff, Save } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        backUrl: string;
        name: string;
        status: 'draft' | 'active' | 'inactive';
        processing: boolean;
        autoSaveStatus?: 'idle' | 'saving' | 'saved' | 'error';
        isCreating?: boolean;
    }>(),
    {
        autoSaveStatus: 'idle',
        isCreating: false,
    },
);

const emit = defineEmits<{
    'update:name': [value: string];
    save: [];
    toggleStatus: [];
}>();

const statusTint = computed(() => {
    switch (props.status) {
        case 'active':
            return 'var(--sp-success)';
        case 'inactive':
            return 'var(--sp-text-secondary)';
        case 'draft':
        default:
            return 'var(--sp-accent-blue)';
    }
});
</script>

<template>
    <div class="sp-glass flex h-14 shrink-0 items-center gap-3 border-b border-soft px-4">
        <Link
            :href="props.backUrl"
            class="flex size-8 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-white/5 hover:text-ink"
        >
            <ArrowLeft class="size-4" />
        </Link>

        <Input
            :model-value="props.name"
            class="h-8 max-w-[280px] border-medium bg-white/5 text-sm font-medium text-ink placeholder:text-ink-subtle"
            :placeholder="t('flows.toolbar.name_placeholder')"
            @update:model-value="emit('update:name', $event as string)"
        />

        <span
            class="inline-flex items-center rounded-pill border px-2.5 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
            :style="{
                color: statusTint,
                borderColor: `color-mix(in oklab, ${statusTint} 45%, transparent)`,
            }"
        >
            {{ t(`flows.status.${props.status}`) }}
        </span>

        <div class="flex-1" />

        <!-- Auto-save indicator (edit mode only) -->
        <div
            v-if="!isCreating"
            class="flex items-center gap-1.5 text-[11px] text-ink-muted"
        >
            <template v-if="autoSaveStatus === 'saving'">
                <Loader2 class="size-3.5 animate-spin" />
                <span>{{ t('flows.toolbar.saving') }}</span>
            </template>
            <template v-else-if="autoSaveStatus === 'saved'">
                <Cloud class="size-3.5 text-sp-success" />
                <span class="text-sp-success">
                    {{ t('flows.toolbar.saved') }}
                </span>
            </template>
            <template v-else-if="autoSaveStatus === 'error'">
                <CloudOff class="size-3.5 text-sp-danger" />
                <span class="text-sp-danger">
                    {{ t('flows.toolbar.save_error') }}
                </span>
            </template>
            <template v-else>
                <Check class="size-3.5" />
                <span>{{ t('flows.toolbar.up_to_date') }}</span>
            </template>
        </div>

        <button
            type="button"
            :disabled="props.processing"
            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10 disabled:opacity-50"
            @click="emit('toggleStatus')"
        >
            <Power v-if="props.status !== 'active'" class="size-3.5" />
            <PowerOff v-else class="size-3.5" />
            {{
                props.status === 'active'
                    ? t('flows.toolbar.deactivate')
                    : t('flows.toolbar.activate')
            }}
        </button>

        <!-- Save button: only in create mode -->
        <button
            v-if="isCreating"
            type="button"
            :disabled="props.processing"
            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3 py-1 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
            @click="emit('save')"
        >
            <Save class="size-3.5" />
            {{ t('flows.toolbar.save') }}
        </button>
    </div>
</template>
