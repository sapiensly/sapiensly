// Shapes of the Playground benchmark API — the server-side interpretation in
// PlaygroundBenchmark::comparison(), shared verbatim with the MCP tool so the
// UI and an external AI read the same verdicts.

export interface BenchmarkModelMetrics {
    execution_ms: number | null;
    ttft_ms: number | null;
    output_tokens_per_second: number | null;
    cost: number | null;
    per_1k_tokens: number | null;
    total_tokens: number | null;
    completion_tokens: number | null;
    reasoning_ratio: number | null;
    cached_prompt_ratio: number | null;
}

export interface BenchmarkModelEntry {
    model: string;
    driver: string | null;
    served_by: string | null;
    status: 'ok' | 'error' | 'running';
    error: string | null;
    run_ids: string[];
    runs_ok: number;
    runs_total: number;
    metrics: BenchmarkModelMetrics;
}

export interface BenchmarkVerdict {
    model: string;
    value: number;
}

export interface BenchmarkComparison {
    status: 'running' | 'complete';
    models: BenchmarkModelEntry[];
    verdicts: {
        fastest_execution: BenchmarkVerdict | null;
        best_ttft: BenchmarkVerdict | null;
        cheapest: BenchmarkVerdict | null;
        highest_throughput: BenchmarkVerdict | null;
    };
    winner: {
        run_id: string;
        model: string | null;
        note: string | null;
    } | null;
}

export interface BenchmarkRun {
    id: string;
    model: string | null;
    driver: string | null;
    served_by: string | null;
    status: 'queued' | 'running' | 'ok' | 'error';
    output_text: string | null;
    error: string | null;
}

export interface BenchmarkDetail {
    id: string;
    capability: string;
    input: { prompt?: string } | null;
    repeats: number;
    user: string | null;
    created_at: string | null;
    comparison: BenchmarkComparison;
    runs: BenchmarkRun[];
}

export interface BenchmarkListItem {
    id: string;
    capability: string;
    excerpt: string | null;
    models: string[];
    status: 'running' | 'complete';
    winner_model: string | null;
    user: string | null;
    created_at: string | null;
}

/**
 * Fixed per-model colors — identity follows the entity across the table dots,
 * the scatter and the answer columns, assigned by first appearance and never
 * re-cycled (dataviz rule: color follows the entity, not its rank).
 */
export const MODEL_COLORS = [
    'var(--sp-accent-blue)',
    'var(--sp-spectrum-magenta)',
    'var(--sp-spectrum-indigo)',
    'var(--sp-accent-cyan)',
    'var(--sp-warning)',
    'var(--sp-success)',
];

export function modelColor(index: number): string {
    return MODEL_COLORS[index % MODEL_COLORS.length];
}
