<script setup lang="ts">
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

defineOptions({ inheritAttrs: false });

const t = themeTokens(useRuntimeTheme());
</script>

<template>
    <hr :class="t.divider" />
</template>
