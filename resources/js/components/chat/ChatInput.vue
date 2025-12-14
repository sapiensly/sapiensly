<script setup lang="ts">
import { ref, computed } from 'vue';
import { Send } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    disabled?: boolean;
    loading?: boolean;
}>();

const emit = defineEmits<{
    submit: [message: string];
}>();

const message = ref('');

const canSubmit = computed(() => {
    return message.value.trim().length > 0 && !props.disabled && !props.loading;
});

function handleSubmit() {
    if (!canSubmit.value) return;

    emit('submit', message.value.trim());
    message.value = '';
}

function handleKeydown(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
        e.preventDefault();
        handleSubmit();
    }
}
</script>

<template>
    <div class="flex gap-2">
        <Textarea
            v-model="message"
            placeholder="Type your message... (Cmd+Enter to send)"
            class="min-h-[60px] flex-1 resize-none"
            :disabled="disabled || loading"
            @keydown="handleKeydown"
        />
        <Button
            :disabled="!canSubmit"
            class="h-auto"
            @click="handleSubmit"
        >
            <Send v-if="!loading" class="h-4 w-4" />
            <span v-else class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
        </Button>
    </div>
</template>
