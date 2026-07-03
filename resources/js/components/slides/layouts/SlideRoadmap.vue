<script setup lang="ts">
import { computed } from 'vue';

interface RoadmapBar {
    label: string;
    /** 1-based period indexes into `periods`, inclusive. */
    start: number;
    end: number;
    status?: 'done' | 'active' | 'upcoming';
}

interface RoadmapLane {
    name: string;
    bars: RoadmapBar[];
}

const props = defineProps<{
    title: string;
    kicker?: string;
    periods: string[];
    lanes: RoadmapLane[];
}>();

const periodCount = computed(() => Math.max(1, props.periods.length));

/** Clamp a bar to the period axis so a slightly-off manifest never overflows. */
function gridColumn(bar: RoadmapBar): string {
    const start = Math.min(Math.max(1, bar.start), periodCount.value);
    const end = Math.min(Math.max(start, bar.end), periodCount.value);
    return `${start} / ${end + 1}`;
}
</script>

<template>
    <div class="slide-roadmap">
        <header>
            <p v-if="kicker" class="kicker">{{ kicker }}</p>
            <h2>{{ title }}</h2>
        </header>
        <div class="board">
            <!-- time axis -->
            <div class="axis-spacer" />
            <div
                class="axis"
                :style="{
                    gridTemplateColumns: `repeat(${periodCount}, 1fr)`,
                }"
            >
                <span v-for="(p, i) in periods" :key="i" class="period">{{
                    p
                }}</span>
            </div>
            <!-- lanes -->
            <template v-for="(lane, li) in lanes" :key="li">
                <div class="lane-name">{{ lane.name }}</div>
                <div
                    class="lane"
                    :style="{
                        gridTemplateColumns: `repeat(${periodCount}, 1fr)`,
                    }"
                >
                    <!-- period gridlines -->
                    <span
                        v-for="i in periodCount"
                        :key="'g' + i"
                        class="gridline"
                        :style="{ gridColumn: `${i} / ${i + 1}` }"
                    />
                    <div
                        v-for="(bar, bi) in lane.bars"
                        :key="bi"
                        class="bar"
                        :class="bar.status ?? 'upcoming'"
                        :style="{
                            gridColumn: gridColumn(bar),
                            gridRow: bi + 1,
                        }"
                    >
                        <span class="bar-label">{{ bar.label }}</span>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.slide-roadmap {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 88px 96px 72px;
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
    margin-bottom: 44px;
}
.board {
    flex: 1;
    min-height: 0;
    display: grid;
    grid-template-columns: 172px 1fr;
    row-gap: 26px;
    align-content: start;
}
.axis {
    display: grid;
    column-gap: 8px;
    border-bottom: 2px solid var(--deck-line);
    padding-bottom: 12px;
}
.period {
    font-size: 15px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--deck-subtle);
    text-align: center;
}
.lane-name {
    font-size: 19px;
    font-weight: 700;
    color: var(--deck-ink);
    padding-right: 24px;
    align-self: start;
    padding-top: 8px;
}
.lane {
    position: relative;
    display: grid;
    column-gap: 8px;
    row-gap: 10px;
    align-items: start;
}
.gridline {
    grid-row: 1 / -1;
    border-left: 1px dashed var(--deck-line);
    height: 100%;
}
.bar {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    min-height: 40px;
    border-radius: 10px;
    padding: 0 16px;
}
.bar-label {
    font-size: 16px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.bar.done {
    background: color-mix(in oklab, var(--deck-accent) 24%, transparent);
    color: var(--deck-ink);
}
.bar.active {
    background: var(--deck-accent);
    color: var(--deck-bg);
}
.bar.upcoming {
    background: transparent;
    border: 2px dashed var(--deck-line);
    color: var(--deck-muted);
}
</style>
