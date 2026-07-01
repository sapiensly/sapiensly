<?php

namespace App\Http\Controllers;

use App\Ai\Tools\Chat\AskUserQuestionTool;
use App\Jobs\RunChatAiJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Support\Chat\ChatMessagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers a multiple-choice question card (a `question` {@see ChatMessage}
 * raised by {@see AskUserQuestionTool}). The chosen text is
 * recorded on the question (locking the card) and posted as a normal user turn,
 * so the assistant resumes with the selection in history.
 */
class ChatQuestionController extends Controller
{
    public function answer(Request $request, Chat $chat, ChatMessage $message): JsonResponse
    {
        if ($chat->user_id !== $request->user()->id || $message->chat_id !== $chat->id) {
            throw new NotFoundHttpException('Question not found.');
        }

        if (($message->message_type ?? '') !== 'question') {
            return new JsonResponse(['message' => 'This message is not a question.'], 422);
        }

        $payload = $message->action_payload ?? [];
        if (($payload['status'] ?? 'pending') !== 'pending') {
            return new JsonResponse(['message' => 'This question has already been answered.'], 409);
        }

        $answer = trim((string) $request->input('answer', ''));
        if ($answer === '') {
            return new JsonResponse(['message' => 'Provide an answer.'], 422);
        }
        $answer = mb_substr($answer, 0, 500);

        // Lock the card: record the choice so it renders answered on reload too.
        $payload['status'] = 'answered';
        $payload['selected'] = $answer;
        $message->update(['action_payload' => $payload]);

        // The selection becomes an ordinary user turn; the assistant continues
        // from it. Reuse the chat's remembered model + tools (questions only
        // arise in plain model chats, so there's no agent turn to run).
        $model = $chat->model;

        $userMessage = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'user',
            'content' => $answer,
            'model' => $model,
            'status' => 'complete',
        ]);

        $placeholder = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'assistant',
            'content' => null,
            'model' => $model,
            'status' => 'pending',
        ]);

        RunChatAiJob::dispatch($placeholder->id, $answer, $model, false, $chat->tool_ids ?? []);

        return new JsonResponse([
            'question' => ChatMessagePresenter::present($message),
            'user_message' => ChatMessagePresenter::present($userMessage),
            'placeholder' => ChatMessagePresenter::present($placeholder),
        ], 201);
    }
}
