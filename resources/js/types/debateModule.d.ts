import type { ChatModelOption } from '@/types/chatModule';

export type { ChatModelOption };

export type DebateStatus =
    | 'pending'
    | 'debating'
    | 'assessing'
    | 'converged'
    | 'completed'
    | 'stopped'
    | 'failed';

export type DebateTurnStatus = 'pending' | 'streaming' | 'complete' | 'error';

export type DebateRoundType = 'opening' | 'rebuttal' | 'synthesis';

export type FinalStance = 'agree' | 'partial' | 'dissent' | null;

export interface DebateParticipantDto {
    id: string;
    model: string;
    provider: string | null;
    display_name: string;
    position: number;
    accent: string | null;
    final_stance: FinalStance;
}

export interface DebateTurnDto {
    id: string;
    debate_round_id: string;
    debate_participant_id: string | null;
    role: 'participant' | 'moderator';
    model: string | null;
    content: string | null;
    stance_summary: string | null;
    status: DebateTurnStatus;
    error: string | null;
    created_at: string | null;
}

export interface ConsensusSummary {
    agreements: string[];
    disagreements: string[];
    verdict: string;
    stances?: Record<string, string>;
}

export interface DebateRoundDto {
    id: string;
    round_number: number;
    type: DebateRoundType;
    status: 'pending' | 'running' | 'complete' | 'error';
    consensus_score: number | null;
    consensus_summary: ConsensusSummary | null;
    consensus_reached: boolean;
    turns: DebateTurnDto[];
}

export interface ActiveDebateDto {
    id: string;
    title: string | null;
    topic: string;
    status: DebateStatus;
    max_rounds: number;
    current_round: number;
    moderator_model: string | null;
    consensus_reached: boolean;
    consensus_score: number | null;
    participants: DebateParticipantDto[];
    rounds: DebateRoundDto[];
}

export interface DebateListItem {
    id: string;
    title: string | null;
    status: DebateStatus;
    last_activity_at: string | null;
}
