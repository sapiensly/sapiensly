<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\KnowledgeBaseStatus;
use App\Http\Requests\KnowledgeBase\StoreKnowledgeBaseRequest;
use App\Http\Requests\KnowledgeBase\UpdateKnowledgeBaseRequest;
use App\Models\KnowledgeBase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request): Response
    {
        $knowledgeBases = KnowledgeBase::query()
            ->where('user_id', $request->user()->id)
            ->withCount('documents')
            ->latest()
            ->paginate(12);

        return Inertia::render('knowledge-bases/Index', [
            'knowledgeBases' => $knowledgeBases,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('knowledge-bases/Create', [
            'documentTypes' => collect(DocumentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
        ]);
    }

    public function store(StoreKnowledgeBaseRequest $request): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'description' => $request->description,
            'status' => KnowledgeBaseStatus::Pending,
            'config' => $request->config ?? [
                'chunk_size' => 1000,
                'chunk_overlap' => 200,
            ],
        ]);

        return to_route('knowledge-bases.show', $knowledgeBase);
    }

    public function show(Request $request, KnowledgeBase $knowledgeBase): Response
    {
        if ($knowledgeBase->user_id !== $request->user()->id) {
            abort(403);
        }

        return Inertia::render('knowledge-bases/Show', [
            'knowledgeBase' => $knowledgeBase->load('documents'),
            'documentTypes' => collect(DocumentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
        ]);
    }

    public function edit(Request $request, KnowledgeBase $knowledgeBase): Response
    {
        if ($knowledgeBase->user_id !== $request->user()->id) {
            abort(403);
        }

        return Inertia::render('knowledge-bases/Edit', [
            'knowledgeBase' => $knowledgeBase,
        ]);
    }

    public function update(UpdateKnowledgeBaseRequest $request, KnowledgeBase $knowledgeBase): RedirectResponse
    {
        $knowledgeBase->update([
            'name' => $request->name,
            'description' => $request->description,
            'config' => $request->config ?? $knowledgeBase->config,
        ]);

        return to_route('knowledge-bases.show', $knowledgeBase);
    }

    public function destroy(Request $request, KnowledgeBase $knowledgeBase): RedirectResponse
    {
        if ($knowledgeBase->user_id !== $request->user()->id) {
            abort(403);
        }

        $knowledgeBase->delete();

        return to_route('knowledge-bases.index');
    }
}
