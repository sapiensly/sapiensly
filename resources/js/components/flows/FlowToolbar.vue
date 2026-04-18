<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Check, Cloud, CloudOff, Loader2, Power, PowerOff, Save } from 'lucide-vue-next';
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

const statusVariant = {
    draft: 'secondary' as const,
    active: 'default' as const,
    inactive: 'outline' as const,
};
</script>

<template>
    <div class="flex h-14 items-center gap-3 border-b bg-background px-4">
        <Button variant="ghost" size="icon" as-child>
            <Link :href="props.backUrl">
                <ArrowLeft class="h-4 w-4" />
            </Link>
        </Button>

        <Input
            :model-value="props.name"
            class="h-8 max-w-[240px] text-sm font-medium"
            :placeholder="t('flows.toolbar.name_placeholder')"
            @update:model-value="emit('update:name', $event as string)"
        />

        <Badge :variant="statusVariant[props.status]">
            {{ t(`flows.status.${props.status}`) }}
        </Badge>

        <div class="flex-1" />

        <!-- Auto-save indicator (edit mode only) -->
        <div v-if="!isCreating" class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <template v-if="autoSaveStatus === 'saving'">
                <Loader2 class="h-3.5 w-3.5 animate-spin" />
                <span>{{ t('flows.toolbar.saving') }}</span>
            </template>
            <template v-else-if="autoSaveStatus === 'saved'">
                <Cloud class="h-3.5 w-3.5 text-green-500" />
                <span class="text-green-600 dark:text-green-400">{{ t('flows.toolbar.saved') }}</span>
            </template>
            <template v-else-if="autoSaveStatus === 'error'">
                <CloudOff class="h-3.5 w-3.5 text-destructive" />
                <span class="text-destructive">{{ t('flows.toolbar.save_error') }}</span>
            </template>
            <template v-else>
                <Check class="h-3.5 w-3.5" />
                <span>{{ t('flows.toolbar.up_to_date') }}</span>
            </template>
        </div>

        <Button
            variant="outline"
            size="sm"
            :disabled="props.processing"
            @click="emit('toggleStatus')"
        >
            <Power
                v-if="props.status !== 'active'"
                class="mr-1.5 h-3.5 w-3.5"
            />
            <PowerOff v-else class="mr-1.5 h-3.5 w-3.5" />
            {{
                props.status === 'active'
                    ? t('flows.toolbar.deactivate')
                    : t('flows.toolbar.activate')
            }}
        </Button>

        <!-- Save button: only in create mode -->
        <Button v-if="isCreating" size="sm" :disabled="props.processing" @click="emit('save')">
            <Save class="mr-1.5 h-3.5 w-3.5" />
            {{ t('flows.toolbar.save') }}
        </Button>
    </div>
</template>
