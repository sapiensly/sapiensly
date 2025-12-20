<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { ExecutionStep } from '@/types/chat';
import { Bot, Brain, Check, ChevronRight, Loader2, Zap } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    steps: ExecutionStep[];
    currentStep?: number | null;
    completedSteps?: number[];
    isProcessing?: boolean;
    collapsible?: boolean;
}>();

const isExpanded = ref(false);

function getAgentIcon(agent: string) {
    switch (agent) {
        case 'knowledge':
            return Brain;
        case 'action':
            return Zap;
        case 'direct':
        default:
            return Bot;
    }
}

function getAgentLabel(agent: string) {
    switch (agent) {
        case 'knowledge':
            return 'Knowledge';
        case 'action':
            return 'Action';
        case 'direct':
            return 'Direct';
        default:
            return agent;
    }
}

function getStepStatus(index: number): 'pending' | 'active' | 'completed' {
    if (props.completedSteps?.includes(index)) {
        return 'completed';
    }
    if (props.currentStep === index) {
        return 'active';
    }
    return 'pending';
}

const summaryText = computed(() => {
    const stepCount = props.steps.length;
    if (stepCount === 1) {
        const step = props.steps[0];
        return `→ ${getAgentLabel(step.agent)}`;
    }
    return `${stepCount} steps planned`;
});

const hasDetails = computed(() => {
    return props.steps.some(step => step.query || step.task);
});
</script>

<template>
    <div class="text-xs text-muted-foreground">
        <!-- Header with collapse toggle -->
        <button
            v-if="collapsible"
            type="button"
            class="flex items-center gap-1.5 hover:text-foreground transition-colors"
            @click="isExpanded = !isExpanded"
        >
            <ChevronRight
                class="h-3 w-3 transition-transform"
                :class="{ 'rotate-90': isExpanded }"
            />
            <Loader2 v-if="isProcessing && currentStep !== null" class="h-3 w-3 animate-spin" />
            <span>{{ summaryText }}</span>

            <!-- Step badges preview (when collapsed) -->
            <div v-if="!isExpanded && steps.length > 1" class="flex items-center gap-0.5 ml-1">
                <template v-for="(step, index) in steps" :key="index">
                    <component
                        :is="getAgentIcon(step.agent)"
                        class="h-3 w-3"
                        :class="{
                            'text-primary': getStepStatus(index) === 'active',
                            'text-green-500': getStepStatus(index) === 'completed',
                            'text-muted-foreground/50': getStepStatus(index) === 'pending',
                        }"
                    />
                </template>
            </div>
        </button>

        <!-- Non-collapsible header -->
        <div v-else class="flex items-center gap-1.5">
            <Loader2 v-if="isProcessing && currentStep !== null" class="h-3 w-3 animate-spin" />
            <span>{{ summaryText }}</span>
        </div>

        <!-- Expanded step list -->
        <div
            v-if="isExpanded || !collapsible"
            class="mt-2 ml-2 space-y-2"
        >
            <div
                v-for="(step, index) in steps"
                :key="index"
                class="flex items-start gap-2 py-1 px-2 rounded-md transition-colors"
                :class="{
                    'bg-primary/5 border-l-2 border-primary': getStepStatus(index) === 'active',
                    'bg-green-500/5': getStepStatus(index) === 'completed',
                }"
            >
                <!-- Step number and status -->
                <div class="flex items-center gap-1.5 shrink-0">
                    <span class="text-[10px] font-medium text-muted-foreground/70">
                        {{ index + 1 }}.
                    </span>
                    <Loader2
                        v-if="getStepStatus(index) === 'active' && isProcessing"
                        class="h-3 w-3 animate-spin text-primary"
                    />
                    <Check
                        v-else-if="getStepStatus(index) === 'completed'"
                        class="h-3 w-3 text-green-500"
                    />
                    <component
                        :is="getAgentIcon(step.agent)"
                        v-else
                        class="h-3 w-3"
                        :class="{
                            'text-primary': getStepStatus(index) === 'active',
                            'text-muted-foreground/50': getStepStatus(index) === 'pending',
                        }"
                    />
                </div>

                <!-- Step content -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-1.5">
                        <span class="font-medium">{{ getAgentLabel(step.agent) }}</span>
                        <Badge
                            v-if="step.urgency && step.urgency !== 'medium'"
                            :variant="step.urgency === 'high' ? 'destructive' : 'secondary'"
                            class="text-[10px] px-1 py-0"
                        >
                            {{ step.urgency }}
                        </Badge>
                    </div>
                    <p v-if="step.query" class="text-muted-foreground/70 truncate">
                        {{ step.query }}
                    </p>
                    <p v-else-if="step.task" class="text-muted-foreground/70 truncate">
                        {{ step.task }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
