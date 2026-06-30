<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Chat\ActionExecutor;
use App\Support\Chat\ChatMessagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ChatActionController extends Controller
{
    public function __construct(private readonly ActionExecutor $executor) {}

    /**
     * Execute the action proposed on an ActionCard.
     */
    public function execute(Request $request, Chat $chat, ChatMessage $message): JsonResponse
    {
        $this->ensureOwner($request, $chat, $message);

        try {
            $result = $this->executor->execute($chat, $message);
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'synthesis_status' => $chat->refresh()->synthesis_status,
            'message' => ChatMessagePresenter::present($result),
        ]);
    }

    /**
     * Dismiss an action proposal without executing it.
     */
    public function dismiss(Request $request, Chat $chat, ChatMessage $message): JsonResponse
    {
        $this->ensureOwner($request, $chat, $message);

        try {
            $this->executor->dismiss($chat, $message);
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['synthesis_status' => $chat->refresh()->synthesis_status]);
    }

    private function ensureOwner(Request $request, Chat $chat, ChatMessage $message): void
    {
        if ($chat->user_id !== $request->user()->id || $message->chat_id !== $chat->id) {
            throw new NotFoundHttpException('Action not found.');
        }
    }
}
