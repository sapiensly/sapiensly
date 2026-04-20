<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Check, Copy } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{ source: string; language?: string }>();

const copied = ref(false);

async function copy(text: string) {
    await navigator.clipboard.writeText(text);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}
</script>

<template>
    <div class="relative">
        <Button
            variant="outline"
            size="sm"
            class="absolute top-2 right-2 h-7 gap-1"
            @click="copy(source)"
        >
            <Check v-if="copied" class="h-3.5 w-3.5" />
            <Copy v-else class="h-3.5 w-3.5" />
            <span class="text-xs">{{ copied ? 'Copied' : 'Copy' }}</span>
        </Button>
        <pre
            class="overflow-auto rounded border bg-muted p-4 text-sm"
        ><code>{{ source }}</code></pre>
    </div>
</template>
