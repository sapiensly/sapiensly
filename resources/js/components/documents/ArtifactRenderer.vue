<script setup lang="ts">
defineProps<{ source: string }>();
</script>

<template>
    <div class="flex h-full flex-col rounded border bg-card">
        <div
            class="shrink-0 border-b bg-muted/40 px-3 py-2 text-xs text-muted-foreground"
        >
            Sandboxed preview — scripts run with no access to this app.
        </div>
        <iframe
            :srcdoc="source"
            sandbox="allow-scripts"
            class="min-h-[600px] w-full flex-1 bg-white"
            title="Artifact preview"
        />
    </div>
</template>
