<script setup lang="ts">
defineProps<{
    title: string;
    subtitle?: string;
    meta?: string;
    logoUrl?: string | null;
}>();
</script>

<template>
    <div class="slide-title">
        <div class="body">
            <div class="rule" />
            <h1>{{ title }}</h1>
            <p v-if="subtitle" class="subtitle">{{ subtitle }}</p>
        </div>
        <footer>
            <span v-if="meta" class="meta">{{ meta }}</span>
            <img v-if="logoUrl" :src="logoUrl" alt="" class="logo" />
        </footer>
    </div>
</template>

<style scoped>
.slide-title {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 96px;
}
.body {
    margin: auto 0;
    max-width: 960px;
}
.rule {
    width: 72px;
    height: 6px;
    border-radius: 3px;
    background: var(--deck-accent);
    margin-bottom: 40px;
}
h1 {
    font-size: 64px;
    line-height: 1.08;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--deck-ink);
}
.subtitle {
    margin-top: 24px;
    font-size: 26px;
    line-height: 1.4;
    color: var(--deck-muted);
    max-width: 840px;
}
footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
}
.meta {
    font-size: 16px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--deck-subtle);
}
.logo {
    height: 36px;
    width: auto;
    object-fit: contain;
    margin-left: auto;
}
</style>
