<?php

namespace App\Services\Debate;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateRound;
use App\Models\DebateTurn;

/**
 * Shapes Debate models into the DTOs the frontend consumes — shared by the
 * Inertia controller and the broadcast events so the wire format stays in
 * lockstep with `resources/js/types/debateModule.d.ts`.
 */
class DebatePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function turn(DebateTurn $turn): array
    {
        return [
            'id' => $turn->id,
            'debate_round_id' => $turn->debate_round_id,
            'debate_participant_id' => $turn->debate_participant_id,
            'role' => $turn->role,
            'model' => $turn->model,
            'content' => $turn->content,
            'stance_summary' => $turn->stance_summary,
            'status' => $turn->status,
            'error' => $turn->error,
            'created_at' => $turn->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function round(DebateRound $round): array
    {
        return [
            'id' => $round->id,
            'round_number' => $round->round_number,
            'type' => $round->type,
            'status' => $round->status,
            'consensus_score' => $round->consensus_score,
            'consensus_summary' => $round->consensus_summary,
            'consensus_reached' => $round->consensus_reached,
            'turns' => $round->turns->map(fn (DebateTurn $t) => self::turn($t))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function participant(DebateParticipant $participant): array
    {
        return [
            'id' => $participant->id,
            'model' => $participant->model,
            'provider' => $participant->provider,
            'display_name' => $participant->display_name,
            'position' => $participant->position,
            'accent' => $participant->accent,
            'final_stance' => $participant->final_stance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function debate(Debate $debate): array
    {
        return [
            'id' => $debate->id,
            'title' => $debate->title,
            'topic' => $debate->topic,
            'status' => $debate->status,
            'max_rounds' => $debate->max_rounds,
            'current_round' => $debate->current_round,
            'moderator_model' => $debate->moderator_model,
            'consensus_reached' => $debate->consensus_reached,
            'consensus_score' => $debate->consensus_score,
            'participants' => $debate->participants->map(fn (DebateParticipant $p) => self::participant($p))->values(),
            'rounds' => $debate->rounds->map(fn (DebateRound $r) => self::round($r))->values(),
        ];
    }
}
