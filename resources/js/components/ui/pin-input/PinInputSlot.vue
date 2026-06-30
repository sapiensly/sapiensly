<script setup lang="ts">
import type { PinInputInputProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { reactiveOmit } from '@vueuse/core';
import { PinInputInput, useForwardProps } from 'reka-ui';
import { cn } from '@/lib/utils';

const props = defineProps<
    PinInputInputProps & { class?: HTMLAttributes['class'] }
>();

const delegatedProps = reactiveOmit(props, 'class');
const forwarded = useForwardProps(delegatedProps);
</script>

<template>
    <PinInputInput
        data-slot="pin-input-slot"
        v-bind="forwarded"
        :class="
            cn(
                'border-input relative flex size-10 items-center justify-center rounded-md border text-center text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:z-10 focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50',
                props.class,
            )
        "
    />
</template>
