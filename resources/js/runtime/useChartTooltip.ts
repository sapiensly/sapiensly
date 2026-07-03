import { ref } from 'vue';

/**
 * Shared cursor-following tooltip for visualization blocks. The container tracks
 * the pointer (onMove) while each mark toggles the tooltip content on
 * mouseenter/leave (showTip/hideTip), so one floating tooltip follows the cursor
 * across SVG marks and HTML bars alike. Bind the returned `card` ref to the
 * block's root, spread `@mousemove="onMove" @mouseleave="hideTip"` on it, and
 * render <ChartTooltip :tip="tip" :x="mouse.x" :y="mouse.y" />. Keeps every
 * dashboard viz consistent without re-implementing the plumbing per component.
 */
export interface ChartTip {
    title: string;
    value?: string;
    color?: string;
}

export function useChartTooltip() {
    const card = ref<HTMLElement | null>(null);
    const mouse = ref({ x: 0, y: 0 });
    const tip = ref<ChartTip | null>(null);

    function onMove(e: MouseEvent): void {
        const r = card.value?.getBoundingClientRect();
        if (r) {
            mouse.value = { x: e.clientX - r.left, y: e.clientY - r.top };
        }
    }
    function showTip(title: string, value?: string, color?: string): void {
        tip.value = { title, value, color };
    }
    function hideTip(): void {
        tip.value = null;
    }

    return { card, mouse, tip, onMove, showTip, hideTip };
}
