<?php

namespace App\Http\Controllers;

use App\Http\Requests\WhatsApp\StoreWhatsAppTemplateRequest;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppTemplateController extends Controller
{
    public function index(WhatsAppConnection $whatsappConnection): Response
    {
        $this->authorize('view', $whatsappConnection);

        return Inertia::render('system/whatsapp/templates/Index', [
            'connection' => $whatsappConnection,
            'templates' => $whatsappConnection->templates()->latest()->get(),
        ]);
    }

    public function store(StoreWhatsAppTemplateRequest $request, WhatsAppConnection $whatsappConnection): RedirectResponse
    {
        $this->authorize('update', $whatsappConnection);

        WhatsAppTemplate::create([
            'whatsapp_connection_id' => $whatsappConnection->id,
            'name' => $request->validated('name'),
            'language' => $request->validated('language'),
            'category' => $request->validated('category'),
            'components' => $request->validated('components'),
            'status' => $request->validated('status') ?? 'unknown',
        ]);

        return back();
    }

    public function update(
        StoreWhatsAppTemplateRequest $request,
        WhatsAppConnection $whatsappConnection,
        WhatsAppTemplate $template,
    ): RedirectResponse {
        $this->authorize('update', $whatsappConnection);

        if ($template->whatsapp_connection_id !== $whatsappConnection->id) {
            abort(404);
        }

        $template->update($request->validated());

        return back();
    }

    public function destroy(WhatsAppConnection $whatsappConnection, WhatsAppTemplate $template): RedirectResponse
    {
        $this->authorize('update', $whatsappConnection);

        if ($template->whatsapp_connection_id !== $whatsappConnection->id) {
            abort(404);
        }

        $template->delete();

        return back();
    }
}
