<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { RoutingDecision } from '@/types/chat';
import { Bot, Brain, ChevronRight, Loader2, Zap } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    routing: RoutingDecision;
    currentAgent?: 'triage' | 'knowledge' | 'action' | null;
    isProcessing?: boolean;
    collapsible?: boolean;
}>();

const isExpanded = ref(false);

const agentLabel = computed(() => {
    switch (props.routing.action) {
        case 'knowledge':
            return 'Knowledge Agent';
        case 'action':
            return 'Action Agent';
        case 'direct':
            return 'Triage Agent';
        default:
            return 'Agent';
    }
});

const agentIcon = computed(() => {
    switch (props.routing.action) {
        case 'knowledge':
            return Brain;
        case 'action':
            return Zap;
        case 'direct':
        default:
            return Bot;
    }
});

const statusText = computed(() => {
    if (props.isProcessing) {
        return `Routing to ${agentLabel.value}...`;
    }
    return `Routed to ${agentLabel.value}`;
});

const hasDetails = computed(() => {
    return props.routing.query || props.routing.task || props.routing.urgency;
});
</script>

<template>
    <div class="flex items-start gap-2 text-xs text-muted-foreground">
        <!-- Collapsible button or static indicator -->
        <button
            v-if="collapsible && hasDetails"
            type="button"
            class="flex items-center gap-1 transition-colors hover:text-foreground"
            @click="isExpanded = !isExpanded"
        >
            <ChevronRight
                class="h-3 w-3 transition-transform"
                :class="{ 'rotate-90': isExpanded }"
            />
            <Loader2 v-if="isProcessing" class="h-3 w-3 animate-spin" />
            <component :is="agentIcon" v-else class="h-3 w-3" />
            <span>{{ statusText }}</span>
        </button>

        <!-- Non-collapsible indicator -->
        <div v-else class="flex items-center gap-1">
            <Loader2 v-if="isProcessing" class="h-3 w-3 animate-spin" />
            <component :is="agentIcon" v-else class="h-3 w-3" />
            <span>{{ statusText }}</span>
        </div>

        <!-- Urgency badge -->
        <Badge
            v-if="routing.urgency && routing.urgency !== 'medium'"
            :variant="routing.urgency === 'high' ? 'destructive' : 'secondary'"
            class="px-1 py-0 text-[10px]"
        >
            {{ routing.urgency }}
        </Badge>
    </div>

    <!-- Expanded details -->
    <div
        v-if="isExpanded && hasDetails"
        class="mt-1 ml-4 space-y-1 border-l border-muted pl-3 text-xs text-muted-foreground"
    >
        <p v-if="routing.query">
            <span class="font-medium">Query:</span> {{ routing.query }}
        </p>
        <p v-if="routing.task">
            <span class="font-medium">Task:</span> {{ routing.task }}
        </p>
        <p v-if="routing.context && Object.keys(routing.context).length > 0">
            <span class="font-medium">Context:</span>
            {{ JSON.stringify(routing.context) }}
        </p>
    </div>
</template>
