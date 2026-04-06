<script setup lang="ts">
import * as ImpersonateController from '@/actions/App/Http/Controllers/Admin/ImpersonateController';
import { Button } from '@/components/ui/button';
import type { AppPageProps } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Eye, X } from 'lucide-vue-next';

const page = usePage<AppPageProps>();

function stopImpersonating() {
    router.post(ImpersonateController.stop().url);
}
</script>

<template>
    <div
        v-if="page.props.impersonating"
        class="fixed top-0 left-0 right-0 z-50 flex items-center justify-center gap-3 bg-amber-500 px-4 py-2 text-sm font-medium text-white"
    >
        <Eye class="h-4 w-4" />
        <span>
            You are impersonating
            <strong>{{ page.props.auth.user.name }}</strong>
            ({{ page.props.auth.user.email }})
        </span>
        <Button
            size="sm"
            variant="secondary"
            class="ml-2 h-7 gap-1 text-xs"
            @click="stopImpersonating"
        >
            <X class="h-3 w-3" />
            Stop Impersonating
        </Button>
    </div>
</template>
