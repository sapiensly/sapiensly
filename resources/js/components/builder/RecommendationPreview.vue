<script setup lang="ts">
/**
 * The live mini-chart on a recommendation card — drawn from the REAL values the
 * recommender computed (no second data fetch), so the analyst shows the shape
 * before you add it. One canvas, four forms; accent resolved from the app's CSS
 * vars so it adopts the org brand.
 */
import { onPaletteChange } from '@/composables/usePaletteSignal';
import { resolveCssColor } from '@/lib/resolveCssColor';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Preview {
    kind: 'pareto' | 'area' | 'gauge' | 'bars' | 'scatter' | 'combo';
    values?: number[];
    value?: number;
    target?: number;
    points?: number[][];
    line?: number[];
}

const props = defineProps<{ preview: Preview }>();
const canvas = ref<HTMLCanvasElement | null>(null);
let ro: ResizeObserver | null = null;

function draw() {
    const cv = canvas.value;
    if (!cv || cv.clientWidth === 0) return;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const w = cv.clientWidth;
    const h = cv.clientHeight;
    cv.width = Math.round(w * dpr);
    cv.height = Math.round(h * dpr);
    const ctx = cv.getContext('2d');
    if (!ctx) return;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);

    const accent = resolveCssColor('var(--sp-accent-blue, #3b82f6)', cv);
    const green = '#16a34a';
    const tail = 'rgba(150,160,200,0.28)';
    const ink = resolveCssColor('var(--sp-text-secondary, #c3cbec)', cv);
    const inkDim = 'rgba(150,160,200,0.6)';
    const p = props.preview;

    if (p.kind === 'pareto')
        paretoChart(ctx, w, h, p.values ?? [], accent, green, tail);
    else if (p.kind === 'area') areaChart(ctx, w, h, p.values ?? [], accent);
    else if (p.kind === 'gauge')
        gaugeChart(
            ctx,
            w,
            h,
            p.value ?? 0,
            p.target ?? 100,
            accent,
            ink,
            inkDim,
        );
    else if (p.kind === 'bars') barsChart(ctx, w, h, p.values ?? [], accent);
    else if (p.kind === 'scatter')
        scatterChart(ctx, w, h, p.points ?? [], accent);
    else if (p.kind === 'combo')
        comboChart(ctx, w, h, p.values ?? [], p.line ?? [], accent, green);
}

/**
 * Volume as bars against a rate as a line — each on its OWN scale, which is the
 * whole point of a dual axis: the two are read against each other, not summed.
 */
function comboChart(
    ctx: CanvasRenderingContext2D,
    w: number,
    h: number,
    values: number[],
    line: number[],
    accent: string,
    green: string,
) {
    if (!values.length) return;
    const pad = 6;
    const base = h - pad;
    const top = pad;
    const maxV = Math.max(...values, 1);
    const slot = (w - pad * 2) / values.length;
    const barW = Math.max(3, slot * 0.55);

    ctx.fillStyle = accent;
    values.forEach((v, i) => {
        const bh = ((v / maxV) * (base - top)) | 0;
        const x = pad + i * slot + (slot - barW) / 2;
        ctx.globalAlpha = i === 0 ? 1 : 0.62;
        ctx.fillRect(x, base - bh, barW, bh);
    });
    ctx.globalAlpha = 1;

    if (line.length < 2) return;
    // The rate rides its own min/max, so a flat-ish rate still reads as flat
    // rather than being flattened into the volume's scale.
    const minL = Math.min(...line);
    const maxL = Math.max(...line);
    const span = maxL - minL || 1;
    ctx.strokeStyle = green;
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    line.forEach((v, i) => {
        const x = pad + i * slot + slot / 2;
        const y =
            base -
            ((v - minL) / span) * (base - top) * 0.8 -
            (base - top) * 0.1;
        if (i === 0) {
            ctx.moveTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    });
    ctx.stroke();

    ctx.fillStyle = green;
    line.forEach((v, i) => {
        const x = pad + i * slot + slot / 2;
        const y =
            base -
            ((v - minL) / span) * (base - top) * 0.8 -
            (base - top) * 0.1;
        ctx.beginPath();
        ctx.arc(x, y, 1.8, 0, Math.PI * 2);
        ctx.fill();
    });
}

function scatterChart(
    ctx: CanvasRenderingContext2D,
    w: number,
    h: number,
    pts: number[][],
    accent: string,
) {
    if (pts.length === 0) return;
    const padL = 12,
        padR = 10,
        padT = 12,
        padB = 12;
    const iw = w - padL - padR,
        ih = h - padT - padB;
    const xs = pts.map((p) => p[0]);
    const ys = pts.map((p) => p[1]);
    const xMin = Math.min(...xs),
        xMax = Math.max(...xs);
    const yMin = Math.min(...ys),
        yMax = Math.max(...ys);
    const X = (v: number) => padL + ((v - xMin) / (xMax - xMin || 1)) * iw;
    const Y = (v: number) => padT + ih - ((v - yMin) / (yMax - yMin || 1)) * ih;
    // axes
    ctx.strokeStyle = 'rgba(150,160,200,0.18)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(padL, padT);
    ctx.lineTo(padL, padT + ih);
    ctx.lineTo(padL + iw, padT + ih);
    ctx.stroke();
    // points (bigger = higher x/volume)
    pts.forEach((p) => {
        ctx.fillStyle = accent;
        ctx.globalAlpha = 0.75;
        ctx.beginPath();
        ctx.arc(X(p[0]), Y(p[1]), 3.4, 0, 7);
        ctx.fill();
    });
    ctx.globalAlpha = 1;
}

function roundedBar(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    w: number,
    h: number,
    r: number,
) {
    r = Math.min(r, w / 2, Math.abs(h));
    ctx.beginPath();
    ctx.moveTo(x, y + h);
    ctx.lineTo(x, y + r);
    ctx.arcTo(x, y, x + r, y, r);
    ctx.lineTo(x + w - r, y);
    ctx.arcTo(x + w, y, x + w, y + r, r);
    ctx.lineTo(x + w, y + h);
    ctx.closePath();
    ctx.fill();
}

function paretoChart(
    ctx: CanvasRenderingContext2D,
    w: number,
    h: number,
    vals: number[],
    accent: string,
    green: string,
    tail: string,
) {
    if (vals.length === 0) return;
    const padT = 12,
        padB = 10,
        padX = 8;
    const iw = w - padX * 2,
        ih = h - padT - padB;
    const n = vals.length,
        slot = iw / n,
        bw = slot * 0.56;
    const max = Math.max(...vals);
    const total = vals.reduce((a, b) => a + b, 0);
    let cum = 0,
        vital = 0;
    const pts: [number, number][] = [];
    vals.forEach((v, i) => {
        cum += v;
        if (cum / total <= 0.8) vital = i + 1;
        pts.push([padX + slot * (i + 0.5), padT + ih - (cum / total) * ih]);
    });
    vals.forEach((v, j) => {
        const bh = (v / max) * ih;
        ctx.fillStyle = j < Math.max(1, vital) ? accent : tail;
        roundedBar(
            ctx,
            padX + slot * (j + 0.5) - bw / 2,
            padT + ih - bh,
            bw,
            bh,
            2,
        );
    });
    ctx.strokeStyle = green;
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    ctx.beginPath();
    pts.forEach((pt, k) =>
        k ? ctx.lineTo(pt[0], pt[1]) : ctx.moveTo(pt[0], pt[1]),
    );
    ctx.stroke();
}

function areaChart(
    ctx: CanvasRenderingContext2D,
    w: number,
    h: number,
    vals: number[],
    accent: string,
) {
    if (vals.length < 2) return;
    const padT = 14,
        padB = 10,
        padX = 6;
    const iw = w - padX * 2,
        ih = h - padT - padB;
    const max = Math.max(...vals),
        min = Math.min(0, ...vals);
    const n = vals.length;
    const X = (i: number) => padX + (iw * i) / (n - 1);
    const Y = (v: number) => padT + ih - ((v - min) / (max - min || 1)) * ih;
    const grad = ctx.createLinearGradient(0, padT, 0, padT + ih);
    grad.addColorStop(0, 'rgba(59,130,246,0.34)');
    grad.addColorStop(1, 'rgba(59,130,246,0)');
    ctx.beginPath();
    ctx.moveTo(X(0), Y(vals[0]));
    vals.forEach((v, i) => i && ctx.lineTo(X(i), Y(v)));
    ctx.lineTo(X(n - 1), padT + ih);
    ctx.lineTo(X(0), padT + ih);
    ctx.closePath();
    ctx.fillStyle = grad;
    ctx.fill();
    ctx.beginPath();
    ctx.moveTo(X(0), Y(vals[0]));
    vals.forEach((v, i) => i && ctx.lineTo(X(i), Y(v)));
    ctx.strokeStyle = accent;
    ctx.lineWidth = 2.2;
    ctx.lineJoin = 'round';
    ctx.stroke();
    ctx.fillStyle = accent;
    ctx.beginPath();
    ctx.arc(X(n - 1), Y(vals[n - 1]), 3, 0, 7);
    ctx.fill();
}

function gaugeChart(
    ctx: CanvasRenderingContext2D,
    w: number,
    h: number,
    value: number,
    target: number,
    accent: string,
    ink: string,
    inkDim: string,
) {
    const cx = w / 2,
        cy = h * 0.8,
        r = Math.min(w * 0.32, h * 0.6);
    const a1 = Math.PI * 0.82,
        a2 = Math.PI * 0.18;
    const start = Math.PI - a1,
        sweep = -(a1 - a2);
    ctx.lineCap = 'round';
    ctx.lineWidth = 8;
    ctx.strokeStyle = 'rgba(150,160,200,0.18)';
    ctx.beginPath();
    ctx.arc(cx, cy, r, Math.PI - a1, Math.PI - a2, false);
    ctx.stroke();
    const scaleMax = Math.max(100, target, value);
    ctx.strokeStyle = accent;
    ctx.beginPath();
    ctx.arc(cx, cy, r, start, start + sweep * (value / scaleMax), false);
    ctx.stroke();
    const at = start + sweep * (target / scaleMax);
    ctx.strokeStyle = ink;
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(cx + Math.cos(at) * (r - 7), cy + Math.sin(at) * (r - 7));
    ctx.lineTo(cx + Math.cos(at) * (r + 7), cy + Math.sin(at) * (r + 7));
    ctx.stroke();
    ctx.fillStyle = ink;
    ctx.textAlign = 'center';
    ctx.font = '700 19px ui-sans-serif, system-ui, sans-serif';
    ctx.fillText(`${value}%`, cx, cy - 3);
    ctx.fillStyle = inkDim;
    ctx.font = '600 9px ui-sans-serif, system-ui, sans-serif';
    ctx.fillText(
        t('apps.builder.analyst.gauge_target', { target }),
        cx,
        cy + 10,
    );
}

function barsChart(
    ctx: CanvasRenderingContext2D,
    w: number,
    h: number,
    vals: number[],
    accent: string,
) {
    if (vals.length === 0) return;
    const padT = 12,
        padB = 10,
        padL = 10,
        padR = 10;
    const ih = h - padT - padB,
        iw = w - padL - padR;
    const max = Math.max(...vals);
    const gap = 5,
        bh = (ih - gap * (vals.length - 1)) / vals.length;
    vals.forEach((v, i) => {
        const y = padT + i * (bh + gap);
        ctx.fillStyle = 'rgba(150,160,200,0.12)';
        roundedBarH(ctx, padL, y, iw, bh, bh / 2);
        ctx.fillStyle = i === 0 ? accent : 'rgba(59,130,246,0.6)';
        roundedBarH(ctx, padL, y, Math.max(bh, (v / max) * iw), bh, bh / 2);
    });
}

function roundedBarH(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    w: number,
    h: number,
    r: number,
) {
    r = Math.min(r, h / 2, w / 2);
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
    ctx.fill();
}

onMounted(() => {
    draw();
    ro = new ResizeObserver(draw);
    if (canvas.value) ro.observe(canvas.value);
});
onBeforeUnmount(() => ro?.disconnect());
watch(() => props.preview, draw, { deep: true });
onPaletteChange(draw);
</script>

<template>
    <canvas ref="canvas" class="block h-full w-full" />
</template>
