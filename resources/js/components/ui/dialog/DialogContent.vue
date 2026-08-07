<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { X } from "@lucide/vue"
import {
  DialogClose,
  DialogContent,
  DialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"
import DialogOverlay from "./DialogOverlay.vue"

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<DialogContentProps & { class?: HTMLAttributes["class"], showCloseButton?: boolean }>(), {
  showCloseButton: true,
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <DialogPortal>
    <DialogOverlay />
    <DialogContent
      data-slot="dialog-content"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="
        cn(
          'bg-background data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 grid w-full translate-x-[-50%] translate-y-[-50%] gap-4 overflow-y-auto overscroll-contain rounded-lg border shadow-lg duration-200',
          // A dialog is centred on the viewport, so content taller than the
          // viewport overflowed in BOTH directions: the bottom went past the
          // fold and the top went ABOVE it, unreachable, with nothing to
          // scroll. Bounding the height and scrolling inside is what makes a
          // tall form usable at all.
          //
          // `dvh`, not `vh`: on a phone `vh` is the viewport with the browser
          // chrome hidden, which is taller than what you can actually see while
          // the address bar is up — the exact reason a modal ends up half off
          // the screen on the device where it matters most.
          'max-h-[calc(100dvh-1rem)] sm:max-h-[calc(100dvh-4rem)]',
          // Near-full width on a phone (a 1rem gutter reads as an inset, not as
          // a wasted margin) and the familiar centred card from sm up.
          'max-w-[calc(100%-1rem)] p-4 sm:max-w-lg sm:p-6',
          props.class,
        )"
    >
      <slot />

      <!--
        Absolute, so it sits in the top-right corner of the CONTENT and scrolls
        up with it on a very tall dialog. Keeping it in view would mean
        splitting this into a fixed frame around a scrolling body, and several
        call sites pass their own `flex flex-col` with `flex-1` children that
        expect to be direct children of this box — a wrapper would break their
        layout to fix a button that DialogHeader's `pr-8` already keeps clear.
      -->
      <DialogClose
        v-if="showCloseButton"
        data-slot="dialog-close"
        class="ring-offset-background focus:ring-ring data-[state=open]:bg-accent data-[state=open]:text-muted-foreground absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
      >
        <X />
        <span class="sr-only">Close</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
