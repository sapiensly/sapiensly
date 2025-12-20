<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { ToolCall, KnowledgeBaseRef } from '@/types/chat';
import { Wrench, Loader2, BookOpen } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    toolCalls?: ToolCall[];
    knowledgeBases?: KnowledgeBaseRef[];
    isExecuting?: boolean;
}>();

const hasToolCalls = computed(() => (props.toolCalls?.length ?? 0) > 0);
const hasKnowledgeBases = computed(() => (props.knowledgeBases?.length ?? 0) > 0);

const formatToolName = (name: string) => {
    // Convert snake_case to Title Case
    return name
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
};
</script>

<template>
    <div class="space-y-1">
        <!-- Knowledge Base indicator -->
        <div
            v-if="hasKnowledgeBases"
            class="flex items-center gap-2 text-sm text-muted-foreground"
        >
            <Loader2
                v-if="isExecuting"
                class="h-4 w-4 animate-spin"
            />
            <BookOpen v-else class="h-4 w-4" />
            <span>{{ isExecuting ? 'Searching' : 'Searched' }}</span>
            <div class="flex flex-wrap gap-1">
                <Badge
                    v-for="(kb, index) in knowledgeBases"
                    :key="index"
                    variant="outline"
                    class="text-xs"
                >
                    {{ kb.name }}
                </Badge>
            </div>
        </div>

        <!-- Tool Call indicator -->
        <div
            v-if="hasToolCalls"
            class="flex items-center gap-2 text-sm text-muted-foreground"
        >
            <Loader2
                v-if="isExecuting"
                class="h-4 w-4 animate-spin"
            />
            <Wrench v-else class="h-4 w-4" />
            <span>{{ isExecuting ? 'Using' : 'Used' }}</span>
            <div class="flex flex-wrap gap-1">
                <Badge
                    v-for="(tool, index) in toolCalls"
                    :key="index"
                    variant="secondary"
                    class="font-mono text-xs"
                >
                    {{ formatToolName(tool.name) }}
                </Badge>
            </div>
        </div>
    </div>
</template>
