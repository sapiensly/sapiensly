<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import axios from 'axios';
import { ExternalLink, Loader2 } from 'lucide-vue-next';
import { type Component, ref, watch } from 'vue';

interface Props {
    open: boolean;
    title: string;
    description: string;
    fetchUrl: string;
    createUrl: string;
    icon: Component;
    columns: { key: string; label: string; type?: 'badge' | 'text' | 'date' | 'size' }[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

interface Item {
    id: string;
    [key: string]: unknown;
}

const items = ref<Item[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

async function fetchItems() {
    loading.value = true;
    error.value = null;
    try {
        const response = await axios.get(props.fetchUrl);
        items.value = response.data.data ?? [];
    } catch {
        error.value = 'Failed to load data.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) fetchItems();
    },
);

function formatValue(value: unknown, type?: string): string {
    if (value == null) return '—';
    if (type === 'date') {
        return new Date(value as string).toLocaleDateString();
    }
    if (type === 'size') {
        const bytes = value as number;
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }
    return String(value);
}

function badgeVariant(value: string): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (value) {
        case 'active':
        case 'ready':
            return 'default';
        case 'draft':
        case 'processing':
        case 'pending':
            return 'secondary';
        case 'failed':
        case 'error':
            return 'destructive';
        default:
            return 'outline';
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[80vh] max-w-3xl flex flex-col">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <component :is="icon" class="h-5 w-5" />
                    {{ title }}
                </DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <div class="flex-1 overflow-y-auto">
                <!-- Loading -->
                <div v-if="loading" class="flex items-center justify-center py-12">
                    <Loader2 class="h-6 w-6 animate-spin text-muted-foreground" />
                </div>

                <!-- Error -->
                <div v-else-if="error" class="py-8 text-center text-sm text-destructive">
                    {{ error }}
                </div>

                <!-- Empty -->
                <div v-else-if="items.length === 0" class="flex flex-col items-center justify-center py-12">
                    <component :is="icon" class="mb-3 h-10 w-10 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">No items yet.</p>
                </div>

                <!-- Table -->
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/50">
                            <th
                                v-for="col in columns"
                                :key="col.key"
                                class="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                            >
                                {{ col.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in items"
                            :key="item.id"
                            class="border-b last:border-0 hover:bg-muted/30"
                        >
                            <td
                                v-for="col in columns"
                                :key="col.key"
                                class="px-3 py-2"
                            >
                                <Badge
                                    v-if="col.type === 'badge'"
                                    :variant="badgeVariant(String(item[col.key] ?? ''))"
                                >
                                    {{ item[col.key] }}
                                </Badge>
                                <span v-else :class="col.key === 'name' ? 'font-medium' : 'text-muted-foreground'">
                                    {{ formatValue(item[col.key], col.type) }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end border-t pt-4">
                <Button as-child size="sm" class="gap-1.5">
                    <a :href="createUrl" target="_blank" rel="noopener">
                        <ExternalLink class="h-3.5 w-3.5" />
                        Create New
                    </a>
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
