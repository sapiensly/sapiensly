<script setup lang="ts">
defineProps<{
    title: string;
    subtitle?: string;
    bullets?: string[];
    cta?: string;
    logoUrl?: string | null;
}>();
</script>

<template>
    <div class="slide-closing">
        <div class="body">
            <div class="rule" />
            <h1>{{ title }}</h1>
            <p v-if="subtitle" class="subtitle">{{ subtitle }}</p>
            <ul v-if="bullets?.length">
                <li v-for="(item, i) in bullets" :key="i">
                    <span class="marker" />
                    <span>{{ item }}</span>
                </li>
            </ul>
            <span v-if="cta" class="cta">{{ cta }}</span>
        </div>
        <footer v-if="logoUrl">
            <img :src="logoUrl" alt="" class="logo" />
        </footer>
    </div>
</template>

<style scoped>
.slide-closing {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 96px;
}
.body {
    margin: auto 0;
    max-width: 920px;
}
.rule {
    width: 72px;
    height: 6px;
    border-radius: 3px;
    background: var(--deck-accent);
    margin-bottom: 40px;
}
h1 {
    font-size: 56px;
    line-height: 1.1;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--deck-ink);
}
.subtitle {
    margin-top: 20px;
    font-size: 25px;
    line-height: 1.4;
    color: var(--deck-muted);
}
ul {
    display: flex;
    flex-direction: column;
    gap: 18px;
    margin-top: 36px;
}
li {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    font-size: 22px;
    line-height: 1.4;
    color: var(--deck-ink);
}
.marker {
    flex-shrink: 0;
    width: 10px;
    height: 10px;
    border-radius: 3px;
    background: var(--deck-accent);
    margin-top: 10px;
}
.cta {
    display: inline-block;
    margin-top: 44px;
    padding: 16px 36px;
    border-radius: 999px;
    background: var(--deck-accent);
    color: #ffffff;
    font-size: 21px;
    font-weight: 700;
}
footer {
    display: flex;
    justify-content: flex-end;
}
.logo {
    height: 36px;
    width: auto;
    object-fit: contain;
}
</style>
