<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { FunctionConfig } from '@/types/tools';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    config: FunctionConfig;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:config': [config: FunctionConfig];
}>();

const functionName = computed({
    get: () => props.config.name ?? '',
    set: (value: string) => {
        emit('update:config', {
            ...props.config,
            name: value,
        });
    },
});

const functionDescription = computed({
    get: () => props.config.description ?? '',
    set: (value: string) => {
        emit('update:config', {
            ...props.config,
            description: value,
        });
    },
});

const parametersJson = computed({
    get: () => {
        const params = props.config.parameters;
        if (!params) return '';
        return JSON.stringify(params, null, 2);
    },
    set: (value: string) => {
        try {
            const parsed = JSON.parse(value);
            emit('update:config', {
                ...props.config,
                parameters: parsed,
            });
        } catch {
            // Invalid JSON, don't update
        }
    },
});
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label for="function-name">{{ t('tools.config.function.name') }}</Label>
            <Input
                id="function-name"
                v-model="functionName"
                :placeholder="t('tools.config.function.name_placeholder')"
                class="font-mono"
            />
            <p class="text-xs text-muted-foreground">
                {{ t('tools.config.function.name_description') }}
            </p>
            <InputError :message="errors['config.name']" />
        </div>

        <div class="grid gap-2">
            <Label for="function-description">{{ t('tools.config.function.description') }}</Label>
            <Textarea
                id="function-description"
                v-model="functionDescription"
                :placeholder="t('tools.config.function.description_example')"
                rows="2"
            />
            <p class="text-xs text-muted-foreground">
                {{ t('tools.config.function.description_hint') }}
            </p>
            <InputError :message="errors['config.description']" />
        </div>

        <div class="grid gap-2">
            <Label for="parameters">{{ t('tools.config.function.parameters_schema') }}</Label>
            <Textarea
                id="parameters"
                v-model="parametersJson"
                placeholder='{
  "type": "object",
  "properties": {
    "location": {
      "type": "string",
      "description": "The city and state"
    }
  },
  "required": ["location"]
}'
                rows="10"
                class="font-mono text-sm"
            />
            <p class="text-xs text-muted-foreground">
                JSON Schema defining the function parameters
            </p>
            <InputError :message="errors['config.parameters']" />
        </div>
    </div>
</template>
