import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/**
 * Track an element's content-box width/height reactively via ResizeObserver.
 * Used by charts to make their SVG viewBox match the container's aspect ratio
 * (so a resized card fills without letterboxing or distortion).
 */
export function useElementSize(target: Ref<HTMLElement | null>) {
    const width = ref(0);
    const height = ref(0);
    let ro: ResizeObserver | null = null;

    const measure = () => {
        const el = target.value;
        if (!el) return;
        width.value = el.clientWidth;
        height.value = el.clientHeight;
    };

    onMounted(() => {
        measure();
        ro = new ResizeObserver(measure);
        if (target.value) ro.observe(target.value);
    });
    onBeforeUnmount(() => ro?.disconnect());

    return { width, height };
}
