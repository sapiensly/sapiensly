<script setup lang="ts">
import type { TabsListProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TabsList } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TabsListProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")
</script>

<template>
  <!--
    A row of tabs slides; it does not widen the page.

    `w-fit` alone sizes to the content and stops there, so five tabs on a phone
    made the DOCUMENT wider than the screen. That is not a local blemish: once
    the page scrolls sideways, every `position: fixed` element is laid out
    against a viewport that no longer matches what you can see — which is how a
    modal ended up with its close button past the right edge.

    `justify-start` rather than centred, because a flex row centred inside a box
    narrower than itself overflows equally in BOTH directions and the first tab
    becomes unreachable by scrolling. With `w-fit` there is no free space to
    centre while the tabs fit, so nothing changes there.

    The scrollbar is hidden: it would eat a third of a 36px-tall row, and the
    tab cut off at the edge already says there is more.
  -->
  <TabsList
    data-slot="tabs-list"
    v-bind="delegatedProps"
    :class="cn(
      'bg-muted text-muted-foreground inline-flex h-9 w-fit max-w-full items-center justify-start overflow-x-auto rounded-lg p-[3px] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
      props.class,
    )"
  >
    <slot />
  </TabsList>
</template>
