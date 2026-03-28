<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

interface Props {
    title: string;
    description: string;
    createUrl?: string;
    createLabel?: string;
}

defineProps<Props>();
const emit = defineEmits<{
    create: [];
}>();
</script>

<template>
    <Card class="border-dashed">
        <CardContent
            class="flex flex-col items-center justify-center py-16 text-center"
        >
            <slot name="icon">
                <div
                    class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted"
                >
                    <Plus class="h-6 w-6 text-muted-foreground" />
                </div>
            </slot>
            <h3 class="mb-2 text-lg font-medium">{{ title }}</h3>
            <p class="mb-6 max-w-sm text-sm text-muted-foreground">
                {{ description }}
            </p>
            <div class="flex gap-2">
                <Button v-if="createUrl && createLabel" as-child>
                    <Link :href="createUrl">
                        <Plus class="mr-2 h-4 w-4" />
                        {{ createLabel }}
                    </Link>
                </Button>
                <Button v-else-if="createLabel" @click="emit('create')">
                    <Plus class="mr-2 h-4 w-4" />
                    {{ createLabel }}
                </Button>
                <slot name="extra" />
            </div>
        </CardContent>
    </Card>
</template>
