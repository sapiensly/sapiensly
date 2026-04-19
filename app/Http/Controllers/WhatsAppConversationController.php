<?php

namespace App\Http\Controllers;

use App\Enums\ConversationStatus;
use App\Http\Requests\WhatsApp\ReplyWhatsAppConversationRequest;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppMessageSender;
use App\Services\WhatsApp\WhatsAppSendException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppConversationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', WhatsAppConversation::class);

        $conversations = WhatsAppConversation::query()
            ->whereHas('channel', fn ($q) => $q->forAccountContext($request->user()))
            ->with(['contact:id,identifier,profile_name,phone_e164', 'channel:id,name'])
            ->latest('last_inbound_at')
            ->paginate(25);

        return Inertia::render('system/whatsapp/Inbox', [
            'conversations' => $conversations,
        ]);
    }

    public function show(WhatsAppConversation $conversation): Response
    {
        $this->authorize('view', $conversation);

        $conversation->load(['contact', 'channel', 'messages' => fn ($q) => $q->orderBy('created_at')]);

        $connection = $conversation->channel?->whatsAppConnection;
        $templates = $connection
            ? WhatsAppTemplate::where('whatsapp_connection_id', $connection->id)
                ->where('status', 'approved')
                ->get(['id', 'name', 'language', 'category'])
            : collect();

        return Inertia::render('system/whatsapp/ConversationShow', [
            'conversation' => $conversation,
            'templates' => $templates,
            'within_session_window' => $conversation->contact?->isWithinSessionWindow() ?? false,
        ]);
    }

    public function takeover(Request $request, WhatsAppConversation $conversation): RedirectResponse
    {
        $this->authorize('takeover', $conversation);

        $conversation->update([
            'status' => ConversationStatus::Escalated,
            'assigned_user_id' => $request->user()->id,
        ]);

        return back();
    }

    public function release(WhatsAppConversation $conversation): RedirectResponse
    {
        $this->authorize('takeover', $conversation);

        $conversation->update([
            'status' => ConversationStatus::Open,
            'assigned_user_id' => null,
        ]);

        return back();
    }

    public function reply(
        ReplyWhatsAppConversationRequest $request,
        WhatsAppConversation $conversation,
        WhatsAppMessageSender $sender,
    ): RedirectResponse {
        $this->authorize('reply', $conversation);

        try {
            if ($request->filled('template_id')) {
                $template = WhatsAppTemplate::findOrFail($request->validated('template_id'));
                $sender->sendTemplate(
                    conversation: $conversation,
                    template: $template,
                    componentParameters: $request->validated('template_params') ?? [],
                    sender: $request->user(),
                );
            } else {
                $sender->sendText(
                    conversation: $conversation,
                    text: $request->validated('content'),
                    sender: $request->user(),
                );
            }
        } catch (WhatsAppSendException $e) {
            return back()->withErrors(['content' => $e->getMessage()]);
        }

        return back();
    }
}
