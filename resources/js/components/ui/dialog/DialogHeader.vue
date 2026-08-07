<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <!--
    Left-aligned at every width, and clear of the close button.

    Centred on a phone (the shadcn default) the title sits directly behind an X
    pinned to the top right: a long one runs under it, and even a short one
    reads as off-centre because the X is the thing breaking the symmetry. The
    right padding is what guarantees they never overlap, whatever the title says.
  -->
  <div
    data-slot="dialog-header"
    :class="cn('flex flex-col gap-2 pr-8 text-left', props.class)"
  >
    <slot />
  </div>
</template>
