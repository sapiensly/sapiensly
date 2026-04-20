<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { AlertTriangle, Check } from '@/lib/admin/icons';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

interface Props {
    ok: boolean;
    label: string;
    hint?: string;
    fixRoute?: string;
}

const props = defineProps<Props>();

const { t } = useI18n();

function fix() {
    if (!props.fixRoute) return;
    router.visit(props.fixRoute);
}
</script>

<template>
    <div
        class="flex items-start justify-between gap-3 rounded-xs border border-soft bg-white/[0.02] px-3 py-2.5"
    >
        <div class="flex min-w-0 items-start gap-2.5">
            <span
                class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full"
                :class="
                    ok
                        ? 'bg-sp-success/15 text-sp-success'
                        : 'bg-sp-warning/15 text-sp-warning'
                "
            >
                <Check v-if="ok" class="size-3" />
                <AlertTriangle v-else class="size-3" />
            </span>
            <div class="min-w-0">
                <p class="text-sm text-ink">{{ label }}</p>
                <p v-if="hint && !ok" class="mt-0.5 text-xs text-ink-muted">
                    {{ hint }}
                </p>
            </div>
        </div>
        <Button
            v-if="!ok && fixRoute"
            variant="ghost"
            size="sm"
            class="shrink-0 text-xs text-accent-blue hover:text-accent-blue-hover"
            @click="fix"
        >
            {{ t('admin_v2.access.posture.fix') }}
        </Button>
    </div>
</template>
