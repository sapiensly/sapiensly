<script setup lang="ts">
interface Column {
    heading: string;
    items: string[];
}

defineProps<{
    title: string;
    left: Column;
    right: Column;
}>();
</script>

<template>
    <div class="slide-two-column">
        <h2>{{ title }}</h2>
        <div class="columns">
            <section v-for="(col, i) in [left, right]" :key="i">
                <h3>{{ col.heading }}</h3>
                <ul>
                    <li v-for="(item, j) in col.items" :key="j">
                        <span class="marker" />
                        <span>{{ item }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>

<style scoped>
.slide-two-column {
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
.columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
    flex: 1;
    min-height: 0;
}
section {
    background: var(--deck-surface);
    border: 1px solid var(--deck-line);
    border-radius: 20px;
    padding: 40px 44px;
    display: flex;
    flex-direction: column;
}
h3 {
    font-size: 22px;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: var(--deck-accent);
    margin-bottom: 28px;
}
ul {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
li {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    font-size: 21px;
    line-height: 1.4;
    color: var(--deck-ink);
}
.marker {
    flex-shrink: 0;
    width: 9px;
    height: 9px;
    border-radius: 3px;
    background: var(--deck-accent);
    margin-top: 10px;
}
</style>
