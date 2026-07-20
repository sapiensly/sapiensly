// Shape of PlaygroundRun::metrics() — derived latency/cost/efficiency figures
// the backend computes so the UI never has to reduce raw provider payloads.
// Every field is null when its inputs are absent ("not measurable"), never 0.
export interface RunMetrics {
    latency: {
        queue_wait_ms: number | null;
        execution_ms: number | null;
        ttft_ms: number | null;
        end_to_end_ms: number | null;
        job_overhead_ms: number | null;
        output_tokens_per_second: number | null;
    };
    cost: {
        total: number | null;
        estimated: boolean;
        per_1k_tokens: number | null;
        input: number | null;
        output: number | null;
        cached: number | null;
        per_useful_output_token: number | null;
    };
    efficiency: {
        prompt_tokens: number | null;
        completion_tokens: number | null;
        total_tokens: number | null;
        reasoning_tokens: number | null;
        reasoning_ratio: number | null;
        cached_prompt_tokens: number | null;
        cached_prompt_ratio: number | null;
    };
}

export function fmtMs(ms: number | null): string {
    if (ms == null) return '—';
    return ms < 1000 ? `${Math.round(ms)} ms` : `${(ms / 1000).toFixed(1)} s`;
}

export function fmtCostValue(cost: number | null): string {
    if (cost == null) return '—';
    // Sub-cent costs need more precision than 2 decimals.
    return cost >= 0.01 ? `$${cost.toFixed(2)}` : `$${cost.toFixed(5)}`;
}

export function fmtInt(n: number | null): string {
    return n == null ? '—' : n.toLocaleString();
}

export function fmtPct(ratio: number | null): string {
    return ratio == null ? '—' : `${Math.round(ratio * 100)}%`;
}
