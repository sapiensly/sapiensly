<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { X } from '@/lib/admin/icons';
import { ref } from 'vue';

/**
 * Enter-to-add, X-to-remove chip list. Dedupes case-insensitively and
 * silently skips empty input. Parent owns the array via v-model.
 *
 * Used by the Access screen for both domain and IP allowlists.
 */
interface Props {
    modelValue: string[];
    placeholder?: string;
    /** Optional regex that a value must match to be accepted. */
    validatePattern?: RegExp;
    /** Lowercase every value before adding (domains). */
    lowercase?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: '',
    lowercase: false,
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string[]): void;
}>();

const input = ref('');
const error = ref<string | null>(null);

function add() {
    const raw = input.value.trim();
    if (raw === '') return;

    const candidate = props.lowercase ? raw.toLowerCase() : raw;

    if (props.validatePattern && !props.validatePattern.test(candidate)) {
        error.value = 'Invalid format';
        return;
    }

    const existing = props.modelValue.map((v) => v.toLowerCase());
    if (existing.includes(candidate.toLowerCase())) {
        // Silent dedupe per handoff: type a dup, we just clear the input.
        input.value = '';
        error.value = null;
        return;
    }

    emit('update:modelValue', [...props.modelValue, candidate]);
    input.value = '';
    error.value = null;
}

function remove(value: string) {
    emit(
        'update:modelValue',
        props.modelValue.filter((v) => v !== value),
    );
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        add();
    } else if (e.key === 'Backspace' && input.value === '' && props.modelValue.length) {
        emit('update:modelValue', props.modelValue.slice(0, -1));
    }
}
</script>

<template>
    <div>
        <div
            class="flex flex-wrap items-center gap-1.5 rounded-xs border border-medium bg-white/5 p-1.5"
        >
            <span
                v-for="chip in modelValue"
                :key="chip"
                class="inline-flex items-center gap-1 rounded-pill border border-soft bg-white/5 px-2 py-0.5 text-xs text-ink"
            >
                {{ chip }}
                <button
                    type="button"
                    class="flex size-3.5 items-center justify-center rounded-full text-ink-muted hover:bg-white/10 hover:text-ink"
                    @click="remove(chip)"
                >
                    <X class="size-2.5" />
                    <span class="sr-only">Remove {{ chip }}</span>
                </button>
            </span>

            <Input
                v-model="input"
                :placeholder="placeholder"
                class="h-7 min-w-[140px] flex-1 border-none bg-transparent p-0 text-sm shadow-none focus-visible:ring-0"
                @keydown="onKeydown"
                @blur="add"
            />
        </div>
        <p v-if="error" class="mt-1 text-xs text-sp-danger">{{ error }}</p>
    </div>
</template>
