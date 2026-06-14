<?php

namespace App\Http\Controllers;

use App\Jobs\Chat\SynthesizeThread;
use App\Models\Chat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ChatSynthesisController extends Controller
{
    /**
     * Manually (re)synthesize a multi-agent thread into an action proposal.
     */
    public function store(Request $request, Chat $chat): JsonResponse
    {
        if ($chat->user_id !== $request->user()->id) {
            throw new NotFoundHttpException('Chat not found.');
        }

        $chat->update(['synthesis_status' => 'pending']);

        SynthesizeThread::dispatch($chat->id)->onQueue('agent-responses');

        return new JsonResponse(['synthesis_status' => 'pending'], 202);
    }
}
