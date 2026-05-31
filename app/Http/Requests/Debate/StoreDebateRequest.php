<?php

namespace App\Http\Requests\Debate;

use App\Models\Agent;
use App\Services\AiProviderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDebateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $reachable = collect(app(AiProviderService::class)->getReachableChatModels($this->user()))
            ->pluck('value')
            ->all();

        // Participants may be reachable models or the user's agents (`agent:{id}`).
        $agentValues = Agent::query()
            ->forAccountContext($this->user())
            ->standalone()
            ->pluck('id')
            ->map(fn (string $id) => 'agent:'.$id)
            ->all();
        $allowedParticipants = array_merge($reachable, $agentValues);

        return [
            'topic' => ['required', 'string', 'max:50000'],
            'title' => ['nullable', 'string', 'max:255'],
            'model_ids' => ['required', 'array', 'min:2', 'max:9'],
            'model_ids.*' => ['string', Rule::in($allowedParticipants)],
            'moderator_model' => ['nullable', 'string', Rule::in($reachable)],
            'max_rounds' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'model_ids.min' => 'Pick at least 2 participants for the debate.',
            'model_ids.max' => 'A debate can have at most 9 participants.',
            'model_ids.*.in' => 'One of the selected participants is not available to you.',
            'moderator_model.in' => 'The selected moderator model is not available to you.',
        ];
    }
}
