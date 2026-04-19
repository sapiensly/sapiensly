<?php

namespace App\Http\Controllers;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppAnalyticsController extends Controller
{
    public function show(WhatsAppConnection $whatsappConnection): Response
    {
        $this->authorize('view', $whatsappConnection);

        $channelId = $whatsappConnection->channel_id;

        $conversationBase = WhatsAppConversation::where('channel_id', $channelId);
        $messageBase = WhatsAppMessage::whereIn(
            'whatsapp_conversation_id',
            WhatsAppConversation::where('channel_id', $channelId)->select('id'),
        );

        $totalMessagesIn = (clone $messageBase)->where('direction', MessageDirection::Inbound)->count();
        $totalMessagesOut = (clone $messageBase)->where('direction', MessageDirection::Outbound)->count();
        $totalDelivered = (clone $messageBase)
            ->where('direction', MessageDirection::Outbound)
            ->whereIn('status', [MessageStatus::Delivered, MessageStatus::Read])
            ->count();

        $deliveryRate = $totalMessagesOut > 0
            ? round(($totalDelivered / $totalMessagesOut) * 100, 1)
            : 0.0;

        return Inertia::render('system/whatsapp/Analytics', [
            'connection' => $whatsappConnection,
            'stats' => [
                'conversations_total' => (clone $conversationBase)->count(),
                'conversations_open' => (clone $conversationBase)
                    ->whereIn('status', [ConversationStatus::Open, ConversationStatus::Pending])
                    ->count(),
                'conversations_escalated' => (clone $conversationBase)
                    ->where('status', ConversationStatus::Escalated)
                    ->count(),
                'conversations_resolved' => (clone $conversationBase)
                    ->where('status', ConversationStatus::Resolved)
                    ->count(),
                'messages_in' => $totalMessagesIn,
                'messages_out' => $totalMessagesOut,
                'delivery_rate' => $deliveryRate,
            ],
        ]);
    }
}
