<script setup lang="ts">
defineProps<{
    value: string;
    label: string;
    kicker?: string;
    delta?: string;
    context?: string;
}>();
</script>

<template>
    <div class="slide-big-number">
        <p v-if="kicker" class="kicker">{{ kicker }}</p>
        <div class="value">{{ value }}</div>
        <div class="label-row">
            <p class="label">{{ label }}</p>
            <span v-if="delta" class="delta">{{ delta }}</span>
        </div>
        <p v-if="context" class="context">{{ context }}</p>
    </div>
</template>

<style scoped>
.slide-big-number {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    height: 100%;
    padding: 96px;
}
.kicker {
    font-size: 18px;
    font-weight: 600;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--deck-subtle);
    margin-bottom: 28px;
}
.value {
    font-size: 168px;
    line-height: 1;
    font-weight: 800;
    letter-spacing: -0.03em;
    color: var(--deck-accent);
}
.label-row {
    display: flex;
    align-items: center;
    gap: 18px;
    margin-top: 32px;
}
.label {
    font-size: 30px;
    font-weight: 600;
    color: var(--deck-ink);
}
.delta {
    font-size: 18px;
    font-weight: 700;
    padding: 6px 16px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--deck-accent) 14%, transparent);
    color: var(--deck-accent);
}
.context {
    margin-top: 24px;
    font-size: 21px;
    line-height: 1.45;
    color: var(--deck-muted);
    max-width: 760px;
}
</style>
