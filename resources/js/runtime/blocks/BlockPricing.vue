<script setup lang="ts">
import { computed, inject } from 'vue';
import { Check } from 'lucide-vue-next';
import { useActionExecutor, type RuntimeAction } from '../useActionExecutor';

interface Tier {
    id?: string;
    name: string;
    price: string;
    period?: string;
    featured?: boolean;
    features?: string[];
    cta?: { label: string; on_click: RuntimeAction[] };
}
interface PricingBlock { id: string; type: 'pricing'; columns?: 2 | 3 | 4; tiers: Tier[] }

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: PricingBlock }>();
const { execute } = useActionExecutor();

const appSlug = inject<string>('appSlug', deriveSlugFromUrl());
function deriveSlugFromUrl(): string {
    const m = window.location.pathname.match(/^\/r\/([a-z][a-z0-9_]*)/);
    return m?.[1] ?? '';
}

const colsClass = computed(
    () => ({ 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-2 lg:grid-cols-3', 4: 'sm:grid-cols-2 lg:grid-cols-4' })[props.block.columns ?? props.block.tiers.length] ?? 'sm:grid-cols-3',
);

async function clickTier(tier: Tier) {
    if (tier.cta) await execute(tier.cta.on_click ?? [], { appSlug });
}
</script>

<template>
    <div :class="['grid grid-cols-1 gap-5', colsClass]">
        <div
            v-for="(tier, i) in block.tiers"
            :key="tier.id ?? i"
            class="flex flex-col gap-4 rounded-2xl border p-6"
            :style="{
                borderColor: tier.featured ? 'var(--sp-accent, #3b82f6)' : 'color-mix(in srgb, currentColor 14%, transparent)',
                borderWidth: tier.featured ? '2px' : '1px',
                backgroundColor: 'color-mix(in srgb, currentColor 4%, transparent)',
            }"
        >
            <div class="text-sm font-semibold uppercase tracking-wide" :style="{ opacity: 0.7 }">{{ tier.name }}</div>
            <div class="flex items-baseline gap-1">
                <span class="text-3xl font-bold tracking-tight">{{ tier.price }}</span>
                <span v-if="tier.period" class="text-sm" :style="{ opacity: 0.6 }">{{ tier.period }}</span>
            </div>
            <ul v-if="tier.features?.length" class="flex flex-col gap-2 text-sm">
                <li v-for="(f, fi) in tier.features" :key="fi" class="flex items-start gap-2">
                    <Check class="mt-0.5 size-4 shrink-0" :style="{ color: 'var(--sp-accent, currentColor)' }" />
                    <span :style="{ opacity: 0.85 }">{{ f }}</span>
                </li>
            </ul>
            <button
                v-if="tier.cta"
                type="button"
                @click="clickTier(tier)"
                class="mt-auto inline-flex items-center justify-center rounded-pill px-4 py-2.5 text-sm font-semibold text-white"
                :style="{ backgroundColor: 'var(--sp-accent, #3b82f6)' }"
            >
                {{ tier.cta.label }}
            </button>
        </div>
    </div>
</template>
