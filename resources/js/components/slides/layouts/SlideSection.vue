<script setup lang="ts">
defineProps<{
    title: string;
    kicker?: string;
    /** 1-based position of this slide, used as the oversized section index. */
    index?: number;
}>();
</script>

<template>
    <div class="slide-section">
        <span v-if="index" class="ghost">{{
            String(index).padStart(2, '0')
        }}</span>
        <div class="body">
            <p v-if="kicker" class="kicker">{{ kicker }}</p>
            <h2>{{ title }}</h2>
            <div class="rule" />
        </div>
    </div>
</template>

<style scoped>
.slide-section {
    position: relative;
    display: flex;
    height: 100%;
    padding: 96px;
    overflow: hidden;
}
.ghost {
    position: absolute;
    right: 48px;
    bottom: -60px;
    font-size: 380px;
    font-weight: 800;
    line-height: 1;
    letter-spacing: -0.04em;
    color: var(--deck-ink);
    opacity: 0.05;
    user-select: none;
}
.body {
    margin: auto 0;
    max-width: 900px;
}
.kicker {
    font-size: 18px;
    font-weight: 600;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--deck-accent);
    margin-bottom: 20px;
}
h2 {
    font-size: 56px;
    line-height: 1.1;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--deck-ink);
}
.rule {
    width: 72px;
    height: 6px;
    border-radius: 3px;
    background: var(--deck-accent);
    margin-top: 36px;
}
</style>
