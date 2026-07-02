<script setup lang="ts">
defineProps<{
    title: string;
    kicker?: string;
    bullets: string[];
}>();
</script>

<template>
    <div class="slide-bullets">
        <header>
            <p v-if="kicker" class="kicker">{{ kicker }}</p>
            <h2>{{ title }}</h2>
        </header>
        <ul>
            <li v-for="(item, i) in bullets" :key="i">
                <span class="marker" />
                <span class="text">{{ item }}</span>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.slide-bullets {
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
    padding-bottom: 28px;
    border-bottom: 1px solid var(--deck-line);
}
ul {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 34px;
    flex: 1;
    margin-top: 20px;
    max-width: 1000px;
}
li {
    display: flex;
    align-items: flex-start;
    gap: 22px;
}
.marker {
    flex-shrink: 0;
    width: 12px;
    height: 12px;
    border-radius: 4px;
    background: var(--deck-accent);
    margin-top: 12px;
}
.text {
    font-size: 26px;
    line-height: 1.4;
    color: var(--deck-ink);
}
</style>
