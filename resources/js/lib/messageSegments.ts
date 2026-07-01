// Builds the ordered render list for an assistant message: text + artifact
// segments (from parseArtifacts) with agent-consultation cards spliced back in
// at the exact point the assistant paused to consult.
//
// The backend emits an inline CONSULT_MARKER into the reply at each consult
// point; here we split the text on it and drop in the matching consultation
// (order-matched to consultation_context). Legacy messages have no markers, so
// their consultations fall back to rendering above the body (`leading`).

import { parseArtifacts, type Segment } from '@/lib/artifacts';
import type { ConsultationDto } from '@/types/chatModule';

// Kept in sync with ChatAiService::CONSULT_MARKER (backend).
export const CONSULT_MARKER = '[[consult]]';

export type MessageSegment =
    | Segment
    | { kind: 'consult'; consultation: ConsultationDto };

export interface MessageContent {
    // Consultations with no positional marker (legacy) — render above the body.
    leading: ConsultationDto[];
    segments: MessageSegment[];
}

export function buildMessageContent(
    content: string | null,
    messageId: string,
    streamDone: boolean,
    consultations: ConsultationDto[],
): MessageContent {
    const { segments } = parseArtifacts(content, messageId, streamDone);
    const out: MessageSegment[] = [];
    let ci = 0;

    for (const seg of segments) {
        if (seg.kind !== 'text') {
            out.push(seg);
            continue;
        }
        const parts = seg.text.split(CONSULT_MARKER);
        for (let i = 0; i < parts.length; i++) {
            if (parts[i]) out.push({ kind: 'text', text: parts[i] });
            // A marker sits between parts i and i+1; fill it with the next
            // consultation, if one has arrived yet (during streaming the marker
            // can briefly precede its card).
            if (i < parts.length - 1 && ci < consultations.length) {
                out.push({
                    kind: 'consult',
                    consultation: consultations[ci++],
                });
            }
        }
    }

    return { leading: consultations.slice(ci), segments: out };
}
