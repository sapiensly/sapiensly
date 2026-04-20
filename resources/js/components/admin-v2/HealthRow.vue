<script setup lang="ts">
import type { HealthCheck } from '@/lib/admin/types';
import {
    Activity,
    Cpu,
    Database,
    HardDrive,
    Radio,
    Server,
    Sparkles,
    Zap,
} from '@/lib/admin/icons';
import { BatteryCharging, Wifi } from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed } from 'vue';

interface Props {
    check: HealthCheck;
}

const props = defineProps<Props>();

const iconMap: Record<string, Component> = {
    llm: BatteryCharging,
    embeddings: Sparkles,
    db: Database,
    queue: Zap,
    storage: HardDrive,
    vector: Cpu,
    cache: Zap,
    server: Server,
    reverb: Wifi,
    websockets: Wifi,
    radio: Radio,
};

/**
 * Per-ID tile tint so each row reads as a distinct service rather than a
 * uniform grey block. Falls back to accent-cyan for unknown IDs.
 */
const tintMap: Record<string, string> = {
    llm: 'var(--sp-success)',
    embeddings: 'var(--sp-spectrum-magenta)',
    db: 'var(--sp-accent-cyan)',
    vector: 'var(--sp-accent-cyan)',
    storage: 'var(--sp-warning)',
    queue: 'var(--sp-warning)',
    cache: 'var(--sp-accent-blue)',
    reverb: 'var(--sp-accent-cyan)',
    websockets: 'var(--sp-accent-cyan)',
};

const icon = computed<Component>(() => iconMap[props.check.id] ?? Activity);
const tint = computed(() => tintMap[props.check.id] ?? 'var(--sp-accent-blue)');

const statusColor = computed(() => {
    switch (props.check.status) {
        case 'ok':
            return 'var(--sp-success)';
        case 'warn':
            return 'var(--sp-warning)';
        case 'error':
            return 'var(--sp-danger)';
    }
});

const timeAgo = computed(() => {
    const then = new Date(props.check.lastCheckAt).getTime();
    const now = Date.now();
    const s = Math.round((now - then) / 1000);
    if (s < 60) return `${s}s ago`;
    const m = Math.round(s / 60);
    if (m < 60) return `${m}m ago`;
    const h = Math.round(m / 60);
    if (h < 24) return `${h}h ago`;
    return `${Math.round(h / 24)}d ago`;
});
</script>

<template>
    <div class="flex items-center gap-3 px-1 py-2.5">
        <div
            class="flex size-9 shrink-0 items-center justify-center rounded-xs"
            :style="{
                backgroundColor: `color-mix(in oklab, ${tint} 15%, transparent)`,
                color: tint,
            }"
        >
            <component :is="icon" class="size-4" />
        </div>

        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-ink">
                {{ check.label }}
            </p>
            <p class="truncate text-xs text-ink-muted">{{ check.detail }}</p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <span
                class="inline-block size-2 rounded-pill"
                :style="{ backgroundColor: statusColor }"
                :title="check.status"
            />
            <span class="text-[11px] text-ink-subtle">{{ timeAgo }}</span>
        </div>
    </div>
</template>
