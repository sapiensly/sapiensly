// Parses assistant message content into text + artifact segments.
//
// The model is instructed to wrap substantial standalone deliverables in
// <artifact title="..." type="code|html|markdown|svg" language="..."> ... </artifact>
// tags. We extract them so the thread shows a compact card and the side panel
// renders the artifact. Handles partial (still-streaming) artifacts whose
// closing tag hasn't arrived yet.

export type ArtifactType = 'code' | 'html' | 'markdown' | 'svg' | 'text';

export interface Artifact {
    id: string;
    title: string;
    type: ArtifactType;
    language: string | null;
    content: string;
    closed: boolean;
}

export type Segment =
    | { kind: 'text'; text: string }
    | { kind: 'artifact'; artifact: Artifact };

export interface ParsedContent {
    segments: Segment[];
    artifacts: Artifact[];
}

const OPEN_TAG = /<artifact\b([^>]*)>/i;
const CLOSE_TAG = /<\/artifact>/i;
const TRAILING_PARTIAL = /<artifact\b[^>]*$/i;

function attr(raw: string, name: string): string | null {
    const m = raw.match(new RegExp(`${name}\\s*=\\s*"([^"]*)"`, 'i'))
        ?? raw.match(new RegExp(`${name}\\s*=\\s*'([^']*)'`, 'i'));
    return m ? m[1] : null;
}

function normalizeType(raw: string | null): ArtifactType {
    const t = (raw ?? '').toLowerCase();
    if (t === 'html' || t === 'markdown' || t === 'svg' || t === 'code' || t === 'text') {
        return t;
    }
    if (t.includes('html')) return 'html';
    if (t.includes('markdown') || t === 'md') return 'markdown';
    if (t.includes('svg')) return 'svg';
    return 'code';
}

export function parseArtifacts(content: string | null, messageId: string): ParsedContent {
    const segments: Segment[] = [];
    const artifacts: Artifact[] = [];

    if (!content) return { segments, artifacts };

    let rest = content;
    let index = 0;

    while (true) {
        const open = rest.match(OPEN_TAG);
        if (!open || open.index === undefined) {
            // Hide a half-streamed opening tag fragment (e.g. "<artifact ti").
            let text = rest;
            const partial = text.search(TRAILING_PARTIAL);
            if (partial !== -1) text = text.slice(0, partial);
            if (text.length) segments.push({ kind: 'text', text });
            break;
        }

        const before = rest.slice(0, open.index);
        if (before.length) segments.push({ kind: 'text', text: before });

        const attrs = open[1] ?? '';
        const afterOpen = rest.slice(open.index + open[0].length);
        const close = afterOpen.match(CLOSE_TAG);

        let body: string;
        let closed: boolean;
        let consumedToEnd = false;

        if (!close || close.index === undefined) {
            body = afterOpen;
            closed = false;
            consumedToEnd = true;
        } else {
            body = afterOpen.slice(0, close.index);
            closed = true;
        }

        const artifact: Artifact = {
            id: `${messageId}-${index}`,
            title: attr(attrs, 'title') || 'Artifact',
            type: normalizeType(attr(attrs, 'type')),
            language: attr(attrs, 'language'),
            content: body.replace(/^\n+/, '').replace(/\s+$/, ''),
            closed,
        };
        artifacts.push(artifact);
        segments.push({ kind: 'artifact', artifact });
        index++;

        if (consumedToEnd) break;
        rest = afterOpen.slice((close!.index ?? 0) + close![0].length);
    }

    return { segments, artifacts };
}

export function extensionFor(a: Artifact): string {
    if (a.type === 'html') return 'html';
    if (a.type === 'svg') return 'svg';
    if (a.type === 'markdown') return 'md';
    const map: Record<string, string> = {
        javascript: 'js', typescript: 'ts', python: 'py', ruby: 'rb', php: 'php',
        java: 'java', csharp: 'cs', cpp: 'cpp', c: 'c', go: 'go', rust: 'rs',
        json: 'json', yaml: 'yml', sql: 'sql', bash: 'sh', shell: 'sh', css: 'css',
        vue: 'vue', jsx: 'jsx', tsx: 'tsx', kotlin: 'kt', swift: 'swift',
    };
    return map[(a.language ?? '').toLowerCase()] ?? 'txt';
}
