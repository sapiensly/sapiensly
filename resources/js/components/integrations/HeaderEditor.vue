<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Plus, Trash2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

export interface HeaderRow {
    key: string;
    value: string;
    enabled?: boolean;
}

const props = defineProps<{
    modelValue: HeaderRow[];
    showEnabled?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [rows: HeaderRow[]];
}>();

const { t } = useI18n();

function updateRow(index: number, patch: Partial<HeaderRow>) {
    const next = [...props.modelValue];
    next[index] = { ...next[index], ...patch };
    emit('update:modelValue', next);
}

function addRow() {
    emit('update:modelValue', [
        ...props.modelValue,
        { key: '', value: '', enabled: true },
    ]);
}

function removeRow(index: number) {
    const next = [...props.modelValue];
    next.splice(index, 1);
    emit('update:modelValue', next);
}
</script>

<template>
    <div class="space-y-2">
        <div
            v-for="(row, index) in modelValue"
            :key="index"
            class="flex items-center gap-2"
        >
            <Checkbox
                v-if="showEnabled"
                :model-value="row.enabled !== false"
                @update:model-value="updateRow(index, { enabled: $event === true })"
            />
            <Input
                :model-value="row.key"
                :placeholder="t('system.integrations.key_value.key_placeholder')"
                class="flex-1"
                @update:model-value="updateRow(index, { key: String($event) })"
            />
            <Input
                :model-value="row.value"
                :placeholder="t('system.integrations.key_value.value_placeholder')"
                class="flex-1"
                @update:model-value="updateRow(index, { value: String($event) })"
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                @click="removeRow(index)"
            >
                <Trash2 class="h-4 w-4 text-destructive" />
            </Button>
        </div>

        <p
            v-if="modelValue.length === 0"
            class="text-xs text-muted-foreground"
        >
            {{ t('system.integrations.key_value.no_rows') }}
        </p>

        <Button
            type="button"
            variant="outline"
            size="sm"
            @click="addRow"
        >
            <Plus class="mr-2 h-4 w-4" />
            {{ t('system.integrations.key_value.add_row') }}
        </Button>
    </div>
</template>
