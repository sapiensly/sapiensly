<script setup lang="ts">
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { ToolType, ToolTypeOption } from '@/types/tools';
import { Code, Layers, Server } from 'lucide-vue-next';

defineProps<{
    toolTypes: ToolTypeOption[];
}>();

const emit = defineEmits<{
    select: [type: ToolType];
}>();

const toolIcon = (type: ToolType) => {
    switch (type) {
        case 'function':
            return Code;
        case 'mcp':
            return Server;
        case 'group':
            return Layers;
        default:
            return Code;
    }
};

const toolColor = (type: ToolType) => {
    switch (type) {
        case 'function':
            return 'text-blue-500';
        case 'mcp':
            return 'text-green-500';
        case 'group':
            return 'text-purple-500';
        default:
            return 'text-muted-foreground';
    }
};
</script>

<template>
    <div class="grid gap-4 md:grid-cols-3">
        <Card
            v-for="type in toolTypes"
            :key="type.value"
            class="cursor-pointer transition-all hover:border-primary hover:shadow-md"
            @click="emit('select', type.value)"
        >
            <CardHeader>
                <div class="mb-2">
                    <component
                        :is="toolIcon(type.value)"
                        class="h-8 w-8"
                        :class="toolColor(type.value)"
                    />
                </div>
                <CardTitle class="text-lg">{{ type.label }}</CardTitle>
                <CardDescription>{{ type.description }}</CardDescription>
            </CardHeader>
        </Card>
    </div>
</template>
