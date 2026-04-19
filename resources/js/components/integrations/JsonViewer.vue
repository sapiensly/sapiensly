<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Check, Copy } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    value: string | null;
    contentType?: string | null;
}

const props = defineProps<Props>();

const copied = ref(false);

const prettyBody = computed<string>(() => {
    if (!props.value) return '';
    const ct = props.contentType ?? '';
    if (ct.includes('json') || props.value.trim().startsWith('{') || props.value.trim().startsWith('[')) {
        try {
            return JSON.stringify(JSON.parse(props.value), null, 2);
        } catch {
            return props.value;
        }
    }
    return props.value;
});

async function copyBody(): Promise<void> {
    try {
        await navigator.clipboard.writeText(prettyBody.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        // noop
    }
}
</script>

<template>
    <div class="relative">
        <Button
            v-if="prettyBody"
            type="button"
            variant="ghost"
            size="icon"
            class="absolute right-2 top-2 z-10"
            @click="copyBody"
        >
            <Check v-if="copied" class="h-4 w-4 text-emerald-500" />
            <Copy v-else class="h-4 w-4" />
        </Button>
        <pre
            class="max-h-[400px] overflow-auto rounded-md border bg-muted/30 p-3 font-mono text-xs leading-5"
        >{{ prettyBody || '—' }}</pre>
    </div>
</template>
