<script setup lang="ts">
defineProps<{
    title: string;
    columns: string[];
    rows: string[][];
}>();
</script>

<template>
    <div class="slide-table">
        <h2>{{ title }}</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th v-for="(col, i) in columns" :key="i">{{ col }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in rows" :key="i">
                        <td v-for="(cell, j) in row" :key="j">
                            <span :class="{ first: j === 0 }">{{ cell }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
.slide-table {
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
.table-wrap {
    flex: 1;
    display: flex;
    align-items: center;
}
table {
    width: 100%;
    border-collapse: collapse;
}
th {
    text-align: left;
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--deck-accent);
    padding: 0 20px 18px;
    border-bottom: 2px solid var(--deck-accent);
}
td {
    padding: 22px 20px;
    font-size: 21px;
    line-height: 1.35;
    color: var(--deck-muted);
    border-bottom: 1px solid var(--deck-line);
}
td .first {
    font-weight: 600;
    color: var(--deck-ink);
}
</style>
