<?php

namespace App\Http\Controllers;

use App\Enums\Visibility;
use App\Http\Requests\Chat\StoreChatProjectRequest;
use App\Http\Requests\Chat\UpdateChatProjectRequest;
use App\Models\ChatProject;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChatProjectController extends Controller
{
    public function store(StoreChatProjectRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $project = ChatProject::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'custom_instructions' => $data['custom_instructions'] ?? null,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => Visibility::Private,
        ]);

        $this->syncKnowledgeBases($project, $data['knowledge_base_ids'] ?? [], $user);

        return back();
    }

    public function update(UpdateChatProjectRequest $request, ChatProject $chatProject): RedirectResponse
    {
        $data = $request->validated();

        $chatProject->update([
            'name' => $data['name'] ?? $chatProject->name,
            'description' => $data['description'] ?? $chatProject->description,
            'custom_instructions' => $data['custom_instructions'] ?? $chatProject->custom_instructions,
        ]);

        if (array_key_exists('knowledge_base_ids', $data)) {
            $this->syncKnowledgeBases($chatProject, $data['knowledge_base_ids'] ?? [], $request->user());
        }

        return back();
    }

    public function destroy(Request $request, ChatProject $chatProject): RedirectResponse
    {
        abort_unless($chatProject->user_id === $request->user()->id, 404);

        $chatProject->delete();

        return back();
    }

    /**
     * Attach only the knowledge bases the user may actually access — silently
     * drops any id that isn't visible to them.
     *
     * @param  array<int, string>  $ids
     */
    private function syncKnowledgeBases(ChatProject $project, array $ids, User $user): void
    {
        $accessible = empty($ids)
            ? []
            : KnowledgeBase::query()
                ->visibleTo($user)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->all();

        $project->knowledgeBases()->sync($accessible);
    }
}
