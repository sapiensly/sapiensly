<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { X } from 'lucide-vue-next';
import { ref } from 'vue';

const model = defineModel<string[]>({ default: () => [] });

const inputValue = ref('');

const addKeyword = () => {
    const keyword = inputValue.value.trim().toLowerCase();
    if (keyword && !model.value.includes(keyword) && model.value.length < 20) {
        model.value = [...model.value, keyword];
        inputValue.value = '';
    }
};

const removeKeyword = (index: number) => {
    model.value = model.value.filter((_, i) => i !== index);
};

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        addKeyword();
    } else if (e.key === 'Backspace' && !inputValue.value && model.value.length > 0) {
        removeKeyword(model.value.length - 1);
    }
};
</script>

<template>
    <div class="space-y-2">
        <div
            v-if="model.length > 0"
            class="flex flex-wrap gap-1.5"
        >
            <Badge
                v-for="(keyword, index) in model"
                :key="keyword"
                variant="secondary"
                class="gap-1 pr-1"
            >
                {{ keyword }}
                <button
                    type="button"
                    class="hover:bg-muted rounded-full p-0.5"
                    @click="removeKeyword(index)"
                >
                    <X class="size-3" />
                </button>
            </Badge>
        </div>
        <Input
            v-model="inputValue"
            :placeholder="model.length >= 20 ? 'Maximum 20 keywords' : 'Type and press Enter to add'"
            :disabled="model.length >= 20"
            @keydown="handleKeydown"
        />
    </div>
</template>
