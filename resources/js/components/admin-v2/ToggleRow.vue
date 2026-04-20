<script setup lang="ts">
import { Switch } from '@/components/ui/switch';

interface Props {
    modelValue: boolean;
    label: string;
    description?: string;
    disabled?: boolean;
}

defineProps<Props>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void;
}>();
</script>

<template>
    <label
        class="flex items-start justify-between gap-4 rounded-xs border border-soft bg-white/[0.02] px-3 py-2.5 transition-colors hover:bg-white/[0.04]"
        :class="disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'"
    >
        <div class="min-w-0">
            <div class="text-sm text-ink">{{ label }}</div>
            <p v-if="description" class="mt-0.5 text-xs text-ink-muted">
                {{ description }}
            </p>
        </div>
        <Switch
            :model-value="modelValue"
            :disabled="disabled"
            class="shrink-0 data-[state=checked]:bg-accent-blue"
            @update:model-value="(v) => emit('update:modelValue', v)"
        />
    </label>
</template>
