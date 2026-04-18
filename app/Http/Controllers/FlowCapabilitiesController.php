<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\Tool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlowCapabilitiesController extends Controller
{
    public function tools(Request $request): JsonResponse
    {
        $tools = Tool::query()
            ->forAccountContext($request->user())
            ->latest()
            ->get(['id', 'name', 'type', 'status', 'description', 'created_at']);

        return response()->json(['data' => $tools]);
    }

    public function documents(Request $request): JsonResponse
    {
        $documents = Document::query()
            ->forAccountContext($request->user())
            ->with('folder:id,name')
            ->latest()
            ->get(['id', 'name', 'type', 'status', 'folder_id', 'file_size', 'created_at']);

        return response()->json(['data' => $documents]);
    }

    public function knowledgeBases(Request $request): JsonResponse
    {
        $knowledgeBases = KnowledgeBase::query()
            ->forAccountContext($request->user())
            ->withCount(['documents', 'attachedDocuments'])
            ->latest()
            ->get(['id', 'name', 'status', 'description', 'created_at']);

        return response()->json(['data' => $knowledgeBases]);
    }

    public function aiProviders(Request $request): JsonResponse
    {
        $providers = AiProvider::query()
            ->forAccountContext($request->user())
            ->latest()
            ->get(['id', 'name', 'driver', 'display_name', 'is_default', 'status', 'created_at']);

        return response()->json(['data' => $providers]);
    }
}
