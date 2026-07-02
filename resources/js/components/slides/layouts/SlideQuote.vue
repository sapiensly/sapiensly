<script setup lang="ts">
defineProps<{
    quote: string;
    attribution?: string;
    role?: string;
}>();
</script>

<template>
    <div class="slide-quote">
        <span class="mark">“</span>
        <blockquote>{{ quote }}</blockquote>
        <div v-if="attribution" class="who">
            <span class="name">{{ attribution }}</span>
            <span v-if="role" class="role">{{ role }}</span>
        </div>
    </div>
</template>

<style scoped>
.slide-quote {
    display: flex;
    flex-direction: column;
    justify-content: center;
    height: 100%;
    padding: 96px 140px;
}
.mark {
    font-size: 140px;
    line-height: 0.6;
    font-weight: 800;
    color: var(--deck-accent);
    margin-bottom: 8px;
    user-select: none;
}
blockquote {
    font-size: 42px;
    line-height: 1.3;
    font-weight: 600;
    letter-spacing: -0.01em;
    color: var(--deck-ink);
    max-width: 960px;
}
.who {
    display: flex;
    align-items: baseline;
    gap: 16px;
    margin-top: 44px;
}
.name {
    font-size: 22px;
    font-weight: 700;
    color: var(--deck-ink);
}
.role {
    font-size: 19px;
    color: var(--deck-subtle);
}
</style>
