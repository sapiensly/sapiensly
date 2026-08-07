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
          'bg-background data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 z-50 grid gap-4 overflow-y-auto overscroll-contain shadow-lg duration-200',
          // ON A PHONE: the whole screen, not a card floating in the middle of
          // it. A dialog holding a twelve-field form has nothing to gain from a
          // gutter and a shadow — every pixel spent on framing is a pixel not
          // spent on the question being asked, and a card that ends before the
          // fold makes it look as though the form does too.
          //
          // `inset-0` also settles what centring never could: a translated box
          // taller than the viewport overflows in BOTH directions, so the top
          // goes ABOVE the fold where nothing can reach it.
          'fixed inset-0 h-full w-full max-w-none rounded-none border-0 p-4',
          // FROM sm UP: the familiar centred card, bounded so it can still
          // never grow past the screen. `dvh` rather than `vh` because on a
          // phone `vh` measures the viewport with the browser chrome hidden —
          // more than you can actually see while the address bar is up.
          'sm:inset-auto sm:top-[50%] sm:left-[50%] sm:h-auto sm:max-h-[calc(100dvh-4rem)] sm:max-w-lg sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-lg sm:border sm:p-6',
          // The zoom belongs to the card. Full-screen it reads as the page
          // lurching, so below sm the dialog only fades in.
          'sm:data-[state=closed]:zoom-out-95 sm:data-[state=open]:zoom-in-95',
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
