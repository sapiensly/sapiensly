<script setup lang="ts">
interface TimelineItem {
    label: string;
    title: string;
    description?: string;
    status?: 'done' | 'active' | 'upcoming';
}

defineProps<{
    title: string;
    kicker?: string;
    items: TimelineItem[];
}>();
</script>

<template>
    <div class="slide-timeline">
        <header>
            <p v-if="kicker" class="kicker">{{ kicker }}</p>
            <h2>{{ title }}</h2>
        </header>
        <div class="track">
            <div class="line" />
            <div
                v-for="(item, i) in items"
                :key="i"
                class="stop"
                :class="item.status ?? 'upcoming'"
            >
                <span class="dot" />
                <span class="label">{{ item.label }}</span>
                <span class="stop-title">{{ item.title }}</span>
                <span v-if="item.description" class="desc">{{
                    item.description
                }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.slide-timeline {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 88px 96px;
}
.kicker {
    font-size: 16px;
    font-weight: 600;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--deck-accent);
    margin-bottom: 12px;
}
h2 {
    font-size: 40px;
    line-height: 1.15;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: var(--deck-ink);
}
.track {
    position: relative;
    display: flex;
    gap: 24px;
    flex: 1;
    align-items: flex-start;
    margin-top: 96px;
}
.line {
    position: absolute;
    top: 7px;
    left: 0;
    right: 0;
    height: 4px;
    border-radius: 2px;
    background: var(--deck-line);
}
.stop {
    position: relative;
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    padding-right: 12px;
}
.dot {
    width: 18px;
    height: 18px;
    border-radius: 999px;
    border: 4px solid var(--deck-line);
    background: var(--deck-bg);
    margin-bottom: 22px;
    z-index: 1;
}
.stop.done .dot {
    border-color: var(--deck-accent);
    background: var(--deck-accent);
}
.stop.active .dot {
    border-color: var(--deck-accent);
    background: var(--deck-bg);
}
.label {
    font-size: 15px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--deck-subtle);
    margin-bottom: 10px;
}
.stop.active .label {
    color: var(--deck-accent);
}
.stop-title {
    font-size: 22px;
    line-height: 1.25;
    font-weight: 700;
    color: var(--deck-ink);
}
.desc {
    margin-top: 10px;
    font-size: 16px;
    line-height: 1.4;
    color: var(--deck-muted);
}
</style>
