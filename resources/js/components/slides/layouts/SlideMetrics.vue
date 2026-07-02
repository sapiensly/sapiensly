<script setup lang="ts">
interface MetricItem {
    value: string;
    label: string;
    delta?: string;
}

defineProps<{
    title?: string;
    items: MetricItem[];
}>();
</script>

<template>
    <div class="slide-metrics">
        <h2 v-if="title">{{ title }}</h2>
        <div class="grid" :style="{ '--cols': items.length }">
            <div v-for="(item, i) in items" :key="i" class="card">
                <div class="value">{{ item.value }}</div>
                <div class="label">{{ item.label }}</div>
                <span v-if="item.delta" class="delta">{{ item.delta }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.slide-metrics {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 88px 96px;
}
h2 {
    font-size: 40px;
    line-height: 1.15;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: var(--deck-ink);
    margin-bottom: 44px;
}
.grid {
    display: grid;
    grid-template-columns: repeat(var(--cols), 1fr);
    gap: 28px;
    flex: 1;
    align-content: center;
}
.card {
    background: var(--deck-surface);
    border: 1px solid var(--deck-line);
    border-radius: 20px;
    padding: 44px 36px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
}
.value {
    font-size: 60px;
    line-height: 1;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--deck-accent);
}
.label {
    font-size: 19px;
    line-height: 1.3;
    font-weight: 500;
    color: var(--deck-muted);
}
.delta {
    font-size: 15px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--deck-accent) 14%, transparent);
    color: var(--deck-accent);
}
</style>
