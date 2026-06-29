<script setup lang="ts">
import {
    Cog,
    Heading1,
    KeyRound,
    ShieldOff,
    Ticket,
    UserCheck,
    UserRound,
} from '@lucide/vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    options: Array<{ value: string; label: string }>;
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

// Each auth method gets an icon + a one-line "when to use it" so the choice is
// guided, not blind. Purposes are keyed by enum value in the locale files.
const iconFor: Record<string, Component> = {
    none: ShieldOff,
    api_key: KeyRound,
    bearer: Ticket,
    basic: UserRound,
    custom_headers: Heading1,
    oauth2_client_credentials: Cog,
    oauth2_auth_code: UserCheck,
};

function purposeFor(value: string): string {
    return t(`system.integrations.auth.purpose.${value}`);
}
</script>

<template>
    <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            :aria-pressed="modelValue === option.value"
            :class="[
                'flex cursor-pointer items-start gap-3 rounded-xs border p-3 text-left transition-colors',
                modelValue === option.value
                    ? 'border-accent-blue/50 bg-accent-blue/[0.08]'
                    : 'border-soft bg-white/[0.03] hover:border-accent-blue/30 hover:bg-white/[0.06]',
            ]"
            @click="emit('update:modelValue', option.value)"
        >
            <div
                class="flex size-8 shrink-0 items-center justify-center rounded-xs"
                :class="
                    modelValue === option.value
                        ? 'bg-accent-blue/15 text-accent-blue'
                        : 'bg-white/[0.04] text-ink-muted'
                "
            >
                <component :is="iconFor[option.value] ?? KeyRound" class="size-4" />
            </div>
            <div class="min-w-0">
                <p class="text-sm font-medium text-ink">{{ option.label }}</p>
                <p class="mt-0.5 text-[11px] leading-snug text-ink-subtle">
                    {{ purposeFor(option.value) }}
                </p>
            </div>
        </button>
    </div>
</template>
